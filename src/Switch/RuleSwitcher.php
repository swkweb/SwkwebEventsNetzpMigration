<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Switch;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\EventsNetzpMigration\Import\Row;
use Swkweb\EventsNetzpMigration\Report\MigrationReport;

/**
 * Converts NetzpEvents6's rule condition. Inside a line item container NetzpEvents6 ignored the yes/no value and matched
 * tickets; the converted condition is therefore "yes" there. Rule payloads are rebuilt by the rule indexer.
 */
class RuleSwitcher implements SwitchStep
{
    private const SOURCE_TYPE = 'netzpEventsRule';
    private const TARGET_TYPE = 'swkwebEventTickets';
    private const LINE_ITEM_CONTAINERS = ['allLineItemsContainer', 'cartLineItemWrapper', 'cartGoodsCount', 'cartGoodsPrice', 'cartLineItemGoodsTotal'];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function run(MigrationReport $report): void
    {
        $conditions = $this->connection->fetchAllAssociative('SELECT `id`, `rule_id`, `parent_id`, `value` FROM `rule_condition` WHERE `type` = :type', ['type' => self::SOURCE_TYPE]);

        foreach ($conditions as $condition) {
            $value = json_decode(\is_string($condition['value']) ? $condition['value'] : '{}', true);
            $hasTickets = \is_array($value) && ($value['shouldHaveEvents'] ?? false) === true;

            if ($this->isInsideLineItemContainer($condition['parent_id'])) {
                if (!$hasTickets) {
                    $report->warn('rule_condition_forced', 'A rule condition inside a line item container had the value "no", which NetzpEvents6 ignored; it is now "yes".', ['ruleId' => Uuid::fromBytesToHex(Row::string($condition['rule_id']))]);
                }
                $hasTickets = true;
            }

            if (!$hasTickets && $this->isUsedForLineItems($condition['rule_id'])) {
                $report->warn('rule_condition_promotion', 'A rule with the value "no" filters promotion line items. NetzpEvents6 ignored the value there and matched tickets; it now matches everything but tickets. Review the promotion.', ['ruleId' => Uuid::fromBytesToHex(Row::string($condition['rule_id']))]);
            }

            $this->connection->executeStatement(
                'UPDATE `rule_condition` SET `type` = :type, `value` = :value WHERE `id` = :id',
                ['type' => self::TARGET_TYPE, 'value' => json_encode(['hasTickets' => $hasTickets], \JSON_THROW_ON_ERROR), 'id' => $condition['id']],
            );
            $this->connection->executeStatement('UPDATE `rule` SET `payload` = NULL, `invalid` = 0 WHERE `id` = :id', ['id' => $condition['rule_id']]);

            $report->count('switch.ruleConditions');
        }
    }

    /**
     * Discount and set group rules are evaluated per line item.
     */
    private function isUsedForLineItems(mixed $ruleId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM `promotion_discount_rule` WHERE `rule_id` = :id UNION SELECT 1 FROM `promotion_setgroup_rule` WHERE `rule_id` = :id',
            ['id' => $ruleId],
        ) !== false;
    }

    private function isInsideLineItemContainer(mixed $parentId): bool
    {
        while (\is_string($parentId)) {
            $parent = $this->connection->fetchAssociative('SELECT `type`, `parent_id` FROM `rule_condition` WHERE `id` = :id', ['id' => $parentId]);
            if ($parent === false) {
                return false;
            }

            if (\in_array($parent['type'], self::LINE_ITEM_CONTAINERS, true)) {
                return true;
            }

            $parentId = $parent['parent_id'];
        }

        return false;
    }
}
