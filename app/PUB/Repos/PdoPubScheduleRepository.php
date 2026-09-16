<?php
declare(strict_types=1);

namespace App\PUB\Repos;

use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * PUB SCHEDULE REPOSITORY
 *
 * Owns persistence for the human-editable Schedule controls:
 *
 *   pub_schedule_settings
 *   pub_schedule_channel_rules
 *
 * This repository stores configuration only.
 *
 * It does NOT:
 *   - decide whether a channel is due
 *   - inspect queued assets
 *   - inspect shipped history
 *   - choose an asset
 *   - apply source-diversity rules
 *   - apply description/type ranking
 *   - dispatch anything
 *   - store rotation pointers/cursors
 *
 * ScheduleManager owns all scheduling logic.
 */
final class PdoPubScheduleRepository
{
    private const SETTINGS_ID = 1;

    public function __construct(
        private PDO $pdo
    ) {}


    /*
     * ========================================================
     * COMPLETE SCHEDULE CONFIG
     * ========================================================
     */

    /**
     * Load the complete human-editable Schedule configuration
     * for one ScheduleManager run.
     *
     * @return array{
     *   settings: array<string, mixed>,
     *   channel_rules: array<int, array<string, mixed>>
     * }
     */
    public function getConfig(): array
    {
        return [
            'settings' =>
                $this->getSettings(),

            'channel_rules' =>
                $this->listChannelRules(),
        ];
    }


    /*
     * ========================================================
     * GLOBAL SETTINGS
     * ========================================================
     */

    /**
     * Fetch the singleton Schedule settings row.
     *
     * @return array{
     *   scheduler_enabled: bool,
     *   timezone: string,
     *   created_at: mixed,
     *   updated_at: mixed
     * }
     */
    public function getSettings(): array
    {
        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    scheduler_enabled,
                    timezone,
                    created_at,
                    updated_at

                FROM pub_schedule_settings

                WHERE pub_schedule_settings_id =
                    :pub_schedule_settings_id

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'pub_schedule_settings_id' =>
                self::SETTINGS_ID,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$row) {
            throw new RuntimeException(
                'PUB Schedule settings row is missing.'
            );
        }


        return $this->normalizeSettingsRow(
            $row
        );
    }


    /**
     * Replace the editable singleton Schedule settings.
     *
     * @return array{
     *   scheduler_enabled: bool,
     *   timezone: string,
     *   created_at: mixed,
     *   updated_at: mixed
     * }
     */
    public function updateSettings(
        bool $schedulerEnabled,
        string $timezone
    ): array {
        $timezone =
            $this->validateTimezone(
                $timezone
            );


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_schedule_settings
                SET
                    scheduler_enabled =
                        :scheduler_enabled,

                    timezone =
                        :timezone

                WHERE pub_schedule_settings_id =
                    :pub_schedule_settings_id
                SQL
            );


        $stmt->execute([
            'scheduler_enabled' =>
                $schedulerEnabled
                    ? 1
                    : 0,

            'timezone' =>
                $timezone,

            'pub_schedule_settings_id' =>
                self::SETTINGS_ID,
        ]);


        return $this->getSettings();
    }


    /**
     * Toggle the global automatic Scheduler master switch.
     *
     * Scheduler OFF does not alter PACKED/QUEUED inventory.
     */
    public function setSchedulerEnabled(
        bool $enabled
    ): array {
        $settings =
            $this->getSettings();


        return $this->updateSettings(
            $enabled,
            (string)$settings[
                'timezone'
            ]
        );
    }


    /**
     * Update only the Scheduler timezone.
     */
    public function setTimezone(
        string $timezone
    ): array {
        $settings =
            $this->getSettings();


        return $this->updateSettings(
            (bool)$settings[
                'scheduler_enabled'
            ],
            $timezone
        );
    }


    /*
     * ========================================================
     * CHANNEL RULES
     * ========================================================
     */

    /**
     * Fetch every configured publishing lane.
     *
     * @return array<int, array{
     *   channel: string,
     *   enabled: bool,
     *   notify_on_publish: bool,
     *   release_interval_minutes: int,
     *   same_source_max: int,
     *   same_source_window_minutes: int,
     *   created_at: mixed,
     *   updated_at: mixed
     * }>
     */
    public function listChannelRules(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT
                    channel,
                    enabled,
                    notify_on_publish,
                    release_interval_minutes,
                    same_source_max,
                    same_source_window_minutes,
                    created_at,
                    updated_at

                FROM pub_schedule_channel_rules

                ORDER BY channel ASC
                SQL
            );


        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];


        return array_values(
            array_map(
                fn (
                    array $row
                ): array =>
                    $this->normalizeChannelRuleRow(
                        $row
                    ),

                $rows
            )
        );
    }


    /**
     * Fetch one configured publishing lane.
     */
    public function getChannelRule(
        string $channel
    ): ?array {
        $channel =
            $this->normalizeChannel(
                $channel
            );


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    channel,
                    enabled,
                    notify_on_publish,
                    release_interval_minutes,
                    same_source_max,
                    same_source_window_minutes,
                    created_at,
                    updated_at

                FROM pub_schedule_channel_rules

                WHERE channel =
                    :channel

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'channel' =>
                $channel,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return $row
            ? $this->normalizeChannelRuleRow(
                $row
            )
            : null;
    }


    /**
     * Create or replace one channel's editable Schedule controls.
     *
     * This is intentionally an upsert so future channels can be
     * introduced from the admin controls without a schema change.
     */
    public function saveChannelRule(
        string $channel,
        bool $enabled,
        int $releaseIntervalMinutes,
        int $sameSourceMax,
        int $sameSourceWindowMinutes,
        ?bool $notifyOnPublish = null
    ): array {
        $channel =
            $this->normalizeChannel(
                $channel
            );


        $this->assertPositive(
            $releaseIntervalMinutes,
            'release_interval_minutes'
        );

        $this->assertPositive(
            $sameSourceMax,
            'same_source_max'
        );

        $this->assertPositive(
            $sameSourceWindowMinutes,
            'same_source_window_minutes'
        );


        if ($notifyOnPublish === null) {
            $existingRule =
                $this->getChannelRule(
                    $channel
                );

            $notifyOnPublish =
                (bool)(
                    $existingRule[
                        'notify_on_publish'
                    ]
                    ?? false
                );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                INSERT INTO pub_schedule_channel_rules (
                    channel,
                    enabled,
                    notify_on_publish,
                    release_interval_minutes,
                    same_source_max,
                    same_source_window_minutes
                ) VALUES (
                    :channel,
                    :enabled,
                    :notify_on_publish,
                    :release_interval_minutes,
                    :same_source_max,
                    :same_source_window_minutes
                )

                ON DUPLICATE KEY UPDATE
                    enabled =
                        VALUES(enabled),

                    notify_on_publish =
                        VALUES(notify_on_publish),

                    release_interval_minutes =
                        VALUES(release_interval_minutes),

                    same_source_max =
                        VALUES(same_source_max),

                    same_source_window_minutes =
                        VALUES(same_source_window_minutes)
                SQL
            );


        $stmt->execute([
            'channel' =>
                $channel,

            'enabled' =>
                $enabled
                    ? 1
                    : 0,

            'notify_on_publish' =>
                $notifyOnPublish
                    ? 1
                    : 0,

            'release_interval_minutes' =>
                $releaseIntervalMinutes,

            'same_source_max' =>
                $sameSourceMax,

            'same_source_window_minutes' =>
                $sameSourceWindowMinutes,
        ]);


        $rule =
            $this->getChannelRule(
                $channel
            );


        if ($rule === null) {
            throw new RuntimeException(
                "PUB Schedule channel '{$channel}' could not be saved."
            );
        }


        return $rule;
    }


    /**
     * Toggle one channel lane without changing its other controls.
     */
    public function setChannelEnabled(
        string $channel,
        bool $enabled
    ): array {
        $channel =
            $this->normalizeChannel(
                $channel
            );


        $rule =
            $this->getChannelRule(
                $channel
            );


        if ($rule === null) {
            throw new RuntimeException(
                "PUB Schedule channel '{$channel}' is not configured."
            );
        }


        return $this->saveChannelRule(
            $channel,
            $enabled,
            (int)$rule[
                'release_interval_minutes'
            ],
            (int)$rule[
                'same_source_max'
            ],
            (int)$rule[
                'same_source_window_minutes'
            ],
            (bool)$rule[
                'notify_on_publish'
            ]
        );
    }


    /**
     * Delete one configured lane.
     *
     * This removes only Schedule configuration. It never touches
     * pub_assets or shipping history.
     */
    public function deleteChannelRule(
        string $channel
    ): void {
        $channel =
            $this->normalizeChannel(
                $channel
            );


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                DELETE FROM pub_schedule_channel_rules

                WHERE channel =
                    :channel
                SQL
            );


        $stmt->execute([
            'channel' =>
                $channel,
        ]);
    }


    /*
     * ========================================================
     * NORMALIZATION / VALIDATION
     * ========================================================
     */

    private function normalizeSettingsRow(
        array $row
    ): array {
        return [
            'scheduler_enabled' =>
                (int)(
                    $row[
                        'scheduler_enabled'
                    ]
                    ?? 0
                ) === 1,

            'timezone' =>
                (string)(
                    $row[
                        'timezone'
                    ]
                    ?? ''
                ),

            'created_at' =>
                $row[
                    'created_at'
                ]
                ?? null,

            'updated_at' =>
                $row[
                    'updated_at'
                ]
                ?? null,
        ];
    }


    private function normalizeChannelRuleRow(
        array $row
    ): array {
        return [
            'channel' =>
                (string)(
                    $row[
                        'channel'
                    ]
                    ?? ''
                ),

            'enabled' =>
                (int)(
                    $row[
                        'enabled'
                    ]
                    ?? 0
                ) === 1,

            'notify_on_publish' =>
                (int)(
                    $row[
                        'notify_on_publish'
                    ]
                    ?? 0
                ) === 1,

            'release_interval_minutes' =>
                (int)(
                    $row[
                        'release_interval_minutes'
                    ]
                    ?? 0
                ),

            'same_source_max' =>
                (int)(
                    $row[
                        'same_source_max'
                    ]
                    ?? 0
                ),

            'same_source_window_minutes' =>
                (int)(
                    $row[
                        'same_source_window_minutes'
                    ]
                    ?? 0
                ),

            'created_at' =>
                $row[
                    'created_at'
                ]
                ?? null,

            'updated_at' =>
                $row[
                    'updated_at'
                ]
                ?? null,
        ];
    }


    private function normalizeChannel(
        string $channel
    ): string {
        $channel =
            strtolower(
                trim(
                    $channel
                )
            );


        if ($channel === '') {
            throw new RuntimeException(
                'PUB Schedule channel cannot be empty.'
            );
        }


        if (strlen($channel) > 50) {
            throw new RuntimeException(
                'PUB Schedule channel exceeds 50 characters.'
            );
        }


        return $channel;
    }


    private function validateTimezone(
        string $timezone
    ): string {
        $timezone =
            trim(
                $timezone
            );


        if ($timezone === '') {
            throw new RuntimeException(
                'PUB Schedule timezone cannot be empty.'
            );
        }


        if (
            !in_array(
                $timezone,
                DateTimeZone::listIdentifiers(),
                true
            )
        ) {
            throw new RuntimeException(
                "PUB Schedule timezone '{$timezone}' is not a valid IANA timezone."
            );
        }


        return $timezone;
    }


    private function assertPositive(
        int $value,
        string $field
    ): void {
        if ($value <= 0) {
            throw new RuntimeException(
                "PUB Schedule {$field} must be greater than zero."
            );
        }
    }
}
