<?php
declare(strict_types=1);

namespace App\COLORS\Repos;

use PDO;
use RuntimeException;
use Throwable;

/** On-demand rebuild of the existing swatch snapshot; readers keep the old table until publication. */
final class PdoMaterializedSwatchRepository
{
    private const COLUMNS = 'id, name, code, brand, chip_num, r, g, b, hex6, hex, '
        . 'hcl_l, hcl_c, hcl_h, brand_name, hue_cats, hue_cat_order, neutral_cats, '
        . 'is_stain, cluster_id, light_cat_id, light_cat_name, light_cat_order, int_only';

    public function __construct(private PDO $pdo) {}

    public function refresh(): array
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Swatch refresh requires its own connection/transaction.');
        }
        $suffix = bin2hex(random_bytes(6));
        $staging = 'mv_swatch_view_refresh_' . $suffix;
        $backup = 'mv_swatch_view_previous_' . $suffix;
        $locked = $created = $published = false;
        $lockName = '';
        try {
            $database = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $lockName = 'swatch_refresh:' . substr(hash('sha256', $database), 0, 32);
            $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
            $lock->execute(['name' => $lockName]);
            if ((int)$lock->fetchColumn() !== 1) {
                throw new RuntimeException('Another swatch refresh is running; no changes made.');
            }
            $locked = true;
            $engine = $this->pdo->query(
                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mv_swatch_view'"
            )->fetchColumn();
            if (strtoupper((string)$engine) !== 'INNODB') {
                throw new RuntimeException('mv_swatch_view must be an existing InnoDB table.');
            }
            $before = (int)$this->pdo->query('SELECT COUNT(*) FROM mv_swatch_view')->fetchColumn();
            // Preserve existing column definitions and indexes. Schema changes
            // happen on the replacement only, never on the live table in place.
            $this->pdo->exec("CREATE TABLE `{$staging}` LIKE mv_swatch_view");
            $created = true;
            $column = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
                . "AND TABLE_NAME='mv_swatch_view' AND COLUMN_NAME='is_inactive'"
            )->fetchColumn();
            if (!(int)$column) {
                $this->pdo->exec("ALTER TABLE `{$staging}` ADD COLUMN is_inactive TINYINT(1) NULL DEFAULT NULL");
            }
            $this->pdo->beginTransaction();
            $expected = (int)$this->pdo->query('SELECT COUNT(*) FROM swatch_view')->fetchColumn();
            if ($expected === 0) {
                throw new RuntimeException('Refusing to replace swatches with an empty source.');
            }
            $projection = implode(', ', array_map(
                static fn(string $column): string => 's.' . trim($column),
                explode(',', self::COLUMNS)
            ));
            // All expensive view calculations and this flag join happen once
            // during refresh. Fuzzy searches need only the finished snapshot.
            $this->pdo->exec(
                "INSERT INTO `{$staging}` (" . self::COLUMNS . ", is_inactive) "
                . "SELECT {$projection}, c.is_inactive FROM swatch_view s INNER JOIN colors c ON c.id=s.id"
            );
            $after = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$staging}`")->fetchColumn();
            if ($after !== $expected) {
                throw new RuntimeException("Swatch row-count mismatch: {$after} vs {$expected}; live table unchanged.");
            }
            $this->pdo->commit();
            // MySQL publishes both renames atomically. Queries never see an
            // empty or partially populated materialized table.
            $this->pdo->exec("RENAME TABLE mv_swatch_view TO `{$backup}`, `{$staging}` TO mv_swatch_view");
            $published = true;
            $result = ['rows_before' => $before, 'rows_after' => $after, 'includes_is_inactive' => true];
            try {
                $this->pdo->exec("DROP TABLE `{$backup}`");
            } catch (Throwable $e) {
                $result['retained_backup_table'] = $backup;
                error_log('Swatch refresh published; backup cleanup failed: ' . $e->getMessage());
            }
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            if ($created && !$published) {
                try { $this->pdo->exec("DROP TABLE IF EXISTS `{$staging}`"); }
                catch (Throwable $e) { error_log('Swatch staging cleanup failed: ' . $e->getMessage()); }
            }
            if ($locked) {
                try {
                    $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
                    $release->execute(['name' => $lockName]);
                } catch (Throwable $e) { error_log('Swatch refresh lock release failed: ' . $e->getMessage()); }
            }
        }
    }
}
