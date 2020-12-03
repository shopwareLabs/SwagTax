<?php
/**
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagTax\Components;

use Doctrine\DBAL\Connection;
use Enlight_Event_EventManager;

class TaxUpdater
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var Enlight_Event_EventManager
     */
    private $eventManager;

    public function __construct(Connection $connection, Enlight_Event_EventManager $eventManager)
    {
        $this->connection = $connection;
        $this->eventManager = $eventManager;
    }

    /**
     * @param bool $cronJobMode
     *
     * @return bool
     */
    public function update($cronJobMode = false)
    {
        $config = $this->getConfig($cronJobMode);

        if (!$config) {
            return false;
        }

        foreach ($config['tax_mapping'] as $oldTaxId => $newTaxId) {
            $newTaxRate = $this->getTaxRateById((int) $newTaxId);

            $this->copyTaxRules($oldTaxId, $newTaxId);

            if (!$config['recalculate_prices']) {
                $this->recalculateShippingCosts($oldTaxId, $newTaxRate);
            }

            $this->updateTaxIds($oldTaxId, $newTaxId);

            if ($config['recalculate_prices']) {
                $this->recalculateProductPrices($oldTaxId, $newTaxRate, $newTaxId, $config['customer_group_mapping']);
            }

            $this->eventManager->notify('Swag_Tax_Updated_TaxRate', [
                'config' => $config,
                'newTaxId' => $newTaxId,
                'newTaxRate' => $newTaxRate,
            ]);
        }

        $this->connection->executeUpdate('UPDATE swag_tax_config SET active = 0');

        return true;
    }

    /**
     * @param int $taxRateId
     *
     * @return float|null
     */
    private function getTaxRateById($taxRateId)
    {
        $result = $this->connection->createQueryBuilder()
            ->select('tax')
            ->from('s_core_tax')
            ->where('id = :taxRateId')
            ->setParameter('taxRateId', $taxRateId)
            ->execute()
            ->fetchColumn();

        if ($result === false) {
            return null;
        }

        return (float) $result;
    }

    /**
     * @param bool $cronJobMode
     *
     * @return array
     */
    private function getConfig($cronJobMode)
    {
        $config = $this->connection->fetchAssoc('SELECT * FROM swag_tax_config WHERE active = 1 LIMIT 1');
        if ($config === false) {
            return null;
        }

        if (empty($config['scheduled_date']) || $config['scheduled_date'] === '0000-00-00 00:00:00') {
            $config['scheduled_date'] = null;

            if ($cronJobMode) {
                return null;
            }
        }

        if ($cronJobMode && time() < strtotime($config['scheduled_date'])) {
            return null;
        }

        $config['recalculate_prices'] = (bool) $config['recalculate_prices'];
        $config['tax_mapping'] = json_decode($config['tax_mapping'], true);
        $config['customer_group_mapping'] = json_decode($config['customer_group_mapping'], true);

        return $config;
    }

    /**
     * @param int $oldTaxId
     * @param int $newTaxId
     */
    private function copyTaxRules($oldTaxId, $newTaxId)
    {
        $data = $this->connection->fetchAll('SELECT * FROM s_core_tax_rules WHERE groupID = ?', [$oldTaxId]);

        foreach ($data as $row) {
            unset($row['id']);
            $row['groupID'] = $newTaxId;

            $this->connection->insert('s_core_tax_rules', $row);
        }
    }

    /**
     * @param int   $oldTaxId
     * @param float $newTaxRate
     * @param int   $newTaxId
     * @param array $customer_group_mapping
     */
    private function recalculateProductPrices($oldTaxId, $newTaxRate, $newTaxId, $customer_group_mapping)
    {
        $oldTaxRate = $this->connection->fetchColumn('SELECT tax FROM s_core_tax WHERE id = ?', [$oldTaxId]);

        $qb = $this->connection->createQueryBuilder();
        $qb->update('s_articles_prices', 'prices')
            ->set('price', sprintf('price/%s*%s', 1 + ($newTaxRate / 100), 1 + ($oldTaxRate / 100)))
            ->where('prices.pricegroup IN (:groups)')
            ->andWhere('(SELECT taxID FROM s_articles WHERE id = prices.articleID) = :newTaxID');

        $qb->setParameter(':groups', $customer_group_mapping, Connection::PARAM_STR_ARRAY);
        $qb->setParameter(':newTaxID', $newTaxId);

        $qb->execute();
    }

    /**
     * @param int   $oldTaxId
     * @param float $newTaxRate
     */
    private function recalculateShippingCosts($oldTaxId, $newTaxRate)
    {
        $oldTaxRate = $this->connection->fetchColumn('SELECT tax FROM s_core_tax WHERE id = ?', [$oldTaxId]);

        $affectedQueryBuilder = $this->connection->createQueryBuilder();
        $affectedQueryBuilder->select('id')
            ->from('s_premium_dispatch', 'dispatch')
            ->where('tax_calculation = :oldTaxId')
            ->setParameter(':oldTaxId', $oldTaxId);

        $affectedDispatchIds = $affectedQueryBuilder->execute()->fetchAll(\PDO::FETCH_COLUMN);

        $recalculatePricesQueryBuilder = $this->connection->createQueryBuilder();
        $recalculatePricesQueryBuilder->update('s_premium_shippingcosts', 'shippingCosts')
            ->set('value', sprintf('value / %s*%s', 1 + ($oldTaxRate / 100), 1 + ($newTaxRate / 100)))
            ->where('shippingCosts.dispatchID IN (:dispatchIds)');

        $recalculatePricesQueryBuilder->setParameter(':dispatchIds', $affectedDispatchIds, Connection::PARAM_INT_ARRAY);

        $recalculatePricesQueryBuilder->execute();
    }

    /**
     * @param int $oldTaxId
     * @param int $newTaxId
     */
    private function updateTaxIds($oldTaxId, $newTaxId)
    {
        $this->connection->executeQuery('UPDATE `s_articles` SET `taxID` = ? WHERE taxID=?', [
            $newTaxId,
            $oldTaxId,
        ]);

        $this->connection->executeQuery('UPDATE `s_emarketing_vouchers` SET `taxconfig` = ? WHERE taxconfig = ?', [
            $newTaxId,
            $oldTaxId,
        ]);

        $this->connection->executeQuery('UPDATE `s_premium_dispatch` SET `tax_calculation` = ? WHERE tax_calculation = ?', [
            $newTaxId,
            $oldTaxId,
        ]);
    }
}
