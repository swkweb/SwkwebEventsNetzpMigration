<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Import;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\MultiInsertQueryQueue;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Replaces all SwkwebEvents data with an {@see ImportPlan} in one transaction. Writes through DBAL, because SwkwebEvents
 * is inactive during the import and has no DAL definitions then.
 */
class TargetWriter
{
    /**
     * Children first. Translations and tags follow through their cascading foreign keys.
     */
    private const CLEARED_TABLES = [
        'swkweb_event_check_in_log',
        'swkweb_event_participant',
        'swkweb_event_slot',
        'swkweb_event',
        'swkweb_event_field_set',
        'swkweb_event_category',
    ];

    private const SNAPSHOT_KEY = 'swkwebEvent';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function write(ImportPlan $plan): void
    {
        $this->connection->transactional(function () use ($plan): void {
            $this->clear();

            $queue = new MultiInsertQueryQueue($this->connection);
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);

            $this->addCategories($queue, $plan);
            $this->addFieldSets($queue, $plan, $now);
            $this->addEvents($queue, $plan);
            $this->addSlots($queue, $plan);
            $this->addParticipants($queue, $plan);
            $queue->execute();

            $this->writeLineItemSnapshots($plan);
        });
    }

    private function clear(): void
    {
        foreach (self::CLEARED_TABLES as $table) {
            $this->connection->executeStatement(\sprintf('DELETE FROM `%s`', $table));
        }

        $this->connection->executeStatement(
            "UPDATE `order_line_item` SET `payload` = JSON_REMOVE(`payload`, :path)
             WHERE `version_id` = :version AND JSON_EXTRACT(`payload`, '$.netzp_event') IS NOT NULL AND JSON_EXTRACT(`payload`, :path) IS NOT NULL",
            ['path' => '$.' . self::SNAPSHOT_KEY, 'version' => self::liveVersion()],
        );
    }

    private function addCategories(MultiInsertQueryQueue $queue, ImportPlan $plan): void
    {
        foreach ($plan->categories as $category) {
            $id = Uuid::fromHexToBytes($category['id']);
            $queue->addInsert('swkweb_event_category', ['id' => $id, 'created_at' => $category['createdAt'], 'updated_at' => $category['updatedAt']]);

            foreach ($category['translations'] as $languageId => $translation) {
                $queue->addInsert('swkweb_event_category_translation', [
                    'swkweb_event_category_id' => $id,
                    'language_id' => Uuid::fromHexToBytes($languageId),
                    'name' => $translation['name'],
                    'teaser' => $translation['teaser'],
                    'created_at' => $category['createdAt'],
                ]);
            }
        }
    }

    private function addFieldSets(MultiInsertQueryQueue $queue, ImportPlan $plan, string $now): void
    {
        foreach ($plan->fieldSets as $fieldSet) {
            $setId = Uuid::fromHexToBytes($fieldSet['id']);
            $queue->addInsert('swkweb_event_field_set', ['id' => $setId, 'created_at' => $now]);

            foreach ($fieldSet['names'] as $languageId => $name) {
                $queue->addInsert('swkweb_event_field_set_translation', [
                    'swkweb_event_field_set_id' => $setId,
                    'language_id' => Uuid::fromHexToBytes($languageId),
                    'name' => $name,
                    'created_at' => $now,
                ]);
            }

            foreach ($fieldSet['fields'] as $field) {
                $fieldId = Uuid::fromHexToBytes($field['id']);
                $queue->addInsert('swkweb_event_field', [
                    'id' => $fieldId,
                    'swkweb_event_field_set_id' => $setId,
                    'name' => $field['name'],
                    'type' => 'text',
                    'required' => 0,
                    'position' => $field['position'],
                    'created_at' => $now,
                ]);

                foreach ($field['labels'] as $languageId => $label) {
                    $queue->addInsert('swkweb_event_field_translation', [
                        'swkweb_event_field_id' => $fieldId,
                        'language_id' => Uuid::fromHexToBytes($languageId),
                        'label' => $label,
                        'created_at' => $now,
                    ]);
                }
            }
        }
    }

    private function addEvents(MultiInsertQueryQueue $queue, ImportPlan $plan): void
    {
        foreach ($plan->events as $event) {
            $id = Uuid::fromHexToBytes($event['id']);
            $queue->addInsert('swkweb_event', [
                'id' => $id,
                'product_id' => self::bytes($event['productId']),
                'product_version_id' => self::liveVersion(),
                'media_id' => self::bytes($event['mediaId']),
                'swkweb_event_category_id' => self::bytes($event['categoryId']),
                'swkweb_event_field_set_id' => self::bytes($event['fieldSetId']),
                'timezone' => $event['timezone'],
                'bookable' => (int) $event['bookable'],
                'collect_attendee_names' => (int) $event['collectAttendeeNames'],
                'archived' => (int) $event['archived'],
                'created_at' => $event['createdAt'],
                'updated_at' => $event['updatedAt'],
            ]);

            foreach ($event['translations'] as $languageId => $translation) {
                $queue->addInsert('swkweb_event_translation', [
                    'swkweb_event_id' => $id,
                    'language_id' => Uuid::fromHexToBytes($languageId),
                    'title' => $translation['title'],
                    'teaser' => $translation['teaser'],
                    'description' => $translation['description'],
                    'url' => $translation['url'],
                    'created_at' => $event['createdAt'],
                ]);
            }

            foreach ($event['tagIds'] as $tagId) {
                $queue->addInsert('swkweb_event_tag', ['swkweb_event_id' => $id, 'tag_id' => Uuid::fromHexToBytes($tagId)]);
            }
        }
    }

    private function addSlots(MultiInsertQueryQueue $queue, ImportPlan $plan): void
    {
        $booked = [];
        foreach ($plan->participants as $participant) {
            if ($participant['status'] !== 'cancelled') {
                $booked[$participant['slotId']] = ($booked[$participant['slotId']] ?? 0) + 1;
            }
        }

        foreach ($plan->slots as $slot) {
            $id = Uuid::fromHexToBytes($slot['id']);
            $queue->addInsert('swkweb_event_slot', [
                'id' => $id,
                'swkweb_event_id' => Uuid::fromHexToBytes($slot['eventId']),
                'start_at' => $slot['startAt'],
                'end_at' => $slot['endAt'],
                'active' => (int) $slot['active'],
                'capacity' => $slot['capacity'],
                'booked_count' => $booked[$slot['id']] ?? 0,
                'created_at' => $slot['createdAt'],
                'updated_at' => $slot['updatedAt'],
            ]);

            foreach ($slot['translations'] as $languageId => $translation) {
                $queue->addInsert('swkweb_event_slot_translation', [
                    'swkweb_event_slot_id' => $id,
                    'language_id' => Uuid::fromHexToBytes($languageId),
                    'location' => $translation['location'],
                    'address' => $translation['address'],
                    'created_at' => $slot['createdAt'],
                ]);
            }
        }
    }

    private function addParticipants(MultiInsertQueryQueue $queue, ImportPlan $plan): void
    {
        foreach ($plan->participants as $participant) {
            $queue->addInsert('swkweb_event_participant', [
                'id' => Uuid::fromHexToBytes($participant['id']),
                'version_id' => self::liveVersion(),
                'swkweb_event_slot_id' => Uuid::fromHexToBytes($participant['slotId']),
                'order_id' => self::bytes($participant['orderId']),
                'order_version_id' => self::liveVersion(),
                'order_line_item_id' => self::bytes($participant['orderLineItemId']),
                'order_line_item_version_id' => self::liveVersion(),
                'position' => $participant['position'],
                'status' => $participant['status'],
                'cancellation_source' => $participant['cancellationSource'],
                'status_before_cancellation' => $participant['statusBeforeCancellation'],
                'name' => $participant['name'],
                'comment' => $participant['comment'],
                'answers' => json_encode($participant['answers'], \JSON_THROW_ON_ERROR),
                'ticket_number' => $participant['ticketNumber'],
                'scan_code' => $participant['scanCode'],
                'created_at' => $participant['createdAt'],
                'updated_at' => $participant['updatedAt'],
            ]);
        }
    }

    /**
     * Only sets the snapshot key: the rest of the payload and `updated_at` of the line item stay as they are.
     */
    private function writeLineItemSnapshots(ImportPlan $plan): void
    {
        foreach ($plan->lineItemSnapshots as $lineItemId => $snapshot) {
            $this->connection->executeStatement(
                "UPDATE `order_line_item` SET `payload` = JSON_SET(`payload`, :path, JSON_EXTRACT(:snapshot, '$'))
                 WHERE `id` = :id AND `version_id` = :version",
                [
                    'path' => '$.' . self::SNAPSHOT_KEY,
                    'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
                    'id' => Uuid::fromHexToBytes($lineItemId),
                    'version' => self::liveVersion(),
                ],
            );
        }
    }

    private static function bytes(?string $id): ?string
    {
        return $id === null ? null : Uuid::fromHexToBytes($id);
    }

    private static function liveVersion(): string
    {
        return Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
    }
}
