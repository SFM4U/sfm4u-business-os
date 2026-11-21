<?php
declare(strict_types=1);

namespace SFM4U\POS;

use DomainException;
use RuntimeException;

final class RegisterSessions
{
    private string $registers;
    private string $sessions;
    public const EXPENSE_REASON = 'operating_expense';
    public const REASONS = ['in'=>['change_float','cash_transfer_in'],
        'out'=>['bank_drop','cash_transfer_out',self::EXPENSE_REASON]];

    public function __construct(private \wpdb $db)
    {
        $this->registers = $db->prefix . 'sfm4u_pos_registers';
        $this->sessions = $db->prefix . 'sfm4u_pos_sessions';
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->registers} (
            merchant bigint unsigned NOT NULL,
            register_code varchar(20) NOT NULL,
            active_session bigint unsigned DEFAULT NULL,
            last_z bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (merchant,register_code)
        ) ENGINE=InnoDB {$collation};");
        dbDelta("CREATE TABLE {$this->sessions} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            merchant bigint unsigned NOT NULL,
            register_code varchar(20) NOT NULL,
            opened_at datetime NOT NULL,
            opened_by bigint unsigned NOT NULL,
            opening_minor bigint unsigned NOT NULL,
            closed_at datetime DEFAULT NULL,
            z_number bigint unsigned DEFAULT NULL,
            snapshot longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY z_series (merchant,register_code,z_number),
            KEY closing_day (merchant,register_code,closed_at)
        ) ENGINE=InnoDB {$collation};");
        foreach ([$this->registers, $this->sessions] as $table) {
            if (strtolower((string) $this->db->get_var($this->db->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ))) !== 'innodb') {
                throw new RuntimeException('Register sessions require transactional tables.');
            }
        }
    }

    private function query(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Register session could not be saved.');
        }
    }

    private function row(string $sql): ?array
    {
        $row = $this->db->get_row($sql, ARRAY_A);
        if ($this->db->last_error !== '') {
            throw new RuntimeException('Register session could not be read.');
        }
        return $row;
    }

    private function register(int $merchant, string $register, bool $lock): ?array
    {
        return $this->row($this->db->prepare(
            'SELECT active_session,last_z FROM %i WHERE merchant=%d AND register_code=%s' . ($lock ? ' FOR UPDATE' : ''),
            $this->registers, $merchant, $register
        ));
    }

    /** Called inside the journal transaction, before any Woo or drawer write. */
    public function active(int $merchant, string $register, bool $required = false): ?int
    {
        $state = $this->register($merchant, $register, true);
        if (!$required && $state === null) {
            return null; // Existing fixtures and historical registers predate enrollment.
        }
        if (empty($state['active_session'])) {
            throw new DomainException('Open the register before recording cash.', 409);
        }
        return (int) $state['active_session'];
    }

    public function open(int $merchant, string $register, int $actor, int $opening): array
    {
        $this->query($this->db->prepare('INSERT INTO %i (merchant,register_code) VALUES (%d,%s) ON DUPLICATE KEY UPDATE merchant=merchant',
            $this->registers, $merchant, $register));
        if ($this->register($merchant, $register, true)['active_session'] !== null) {
            throw new DomainException('This register is already open.', 409);
        }
        $this->query($this->db->prepare('INSERT INTO %i (merchant,register_code,opened_at,opened_by,opening_minor)
            VALUES (%d,%s,UTC_TIMESTAMP(),%d,%d)', $this->sessions, $merchant, $register, $actor, $opening));
        $id = (int) $this->db->insert_id;
        $this->query($this->db->prepare('UPDATE %i SET active_session=%d WHERE merchant=%d AND register_code=%s',
            $this->registers, $id, $merchant, $register));
        return ['session_id'=>$id, 'opening_minor'=>$opening, 'register'=>$register];
    }

    public function assertActive(int $merchant, string $register, int $session): void
    {
        if ($this->active($merchant, $register, true) !== $session) {
            throw new DomainException('This request belongs to a different register session.', 409);
        }
    }

    public function cashTotals(int $merchant, string $register, int $session): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT channel,response FROM %i WHERE merchant_id=%d AND channel IN ('pos','pos_credit','pos_drawer','pos_due')
             AND response IS NOT NULL",
            $this->db->prefix . 'sfm4u_inventory_requests', $merchant
        ), ARRAY_A);
        if ($this->db->last_error !== '') {
            throw new RuntimeException('Register cash ledger could not be read.');
        }
        return $this->sumCash($rows, $register, $session);
    }

    public function dailyCash(int $merchant, string $register): array
    {
        $day = gmdate('Y-m-d');
        $end = gmdate('Y-m-d', time() + DAY_IN_SECONDS);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT channel,response FROM %i WHERE merchant_id=%d AND created_at >= %s AND created_at < %s
             AND channel IN ('pos','pos_credit','pos_drawer','pos_due') AND response IS NOT NULL",
            $this->db->prefix . 'sfm4u_inventory_requests', $merchant, $day, $end
        ), ARRAY_A);
        if ($this->db->last_error !== '') {
            throw new RuntimeException('Daily register cash ledger could not be read.');
        }
        return ['kind' => 'DAY', 'date' => $day] + $this->sumCash($rows, $register);
    }

    private function sumCash(array $rows, string $register, ?int $session = null): array
    {
        $totals = array_fill_keys(['cash_sales_minor','cash_returns_minor','cash_in_minor','cash_out_minor','cash_expense_minor'], 0);
        foreach ($rows as $row) {
            $event = json_decode($row['response'], true, 32, JSON_THROW_ON_ERROR);
            $cash = $event['sale'] ?? $event;
            if ($event['register'] !== $register || ($session !== null && ($cash['session_id'] ?? 0) !== $session)
                || ($row['channel'] === 'pos_drawer' && !isset($cash['direction']))) {
                continue;
            }
            [$field, $amount] = match ($row['channel']) {
                'pos' => ['cash_sales_minor', $cash['cash_received_minor'] ?? $cash['tender_minor'] - $cash['change_minor']],
                'pos_due' => ['cash_in_minor', $cash['paid_minor']],
                'pos_credit' => ['cash_returns_minor', $cash['cash_refund_minor'] ?? $cash['refund_minor']],
                'pos_drawer' => ['cash_' . $cash['direction'] . '_minor', $cash['amount_minor']],
            };
            $totals[$field] += $amount;
            if ($field === 'cash_out_minor' && ($cash['reason'] ?? '') === self::EXPENSE_REASON) {
                $totals['cash_expense_minor'] += $amount;
            }
        }
        return $totals;
    }

    public function close(int $merchant, string $register, int $session, int $actor, int $counted, string $approval): array
    {
        $this->assertActive($merchant, $register, $session);
        $row = $this->row($this->db->prepare('SELECT opened_at,opened_by,opening_minor FROM %i WHERE id=%d FOR UPDATE',
            $this->sessions, $session));
        $cash = $this->cashTotals($merchant, $register, $session);
        $opening = (int) $row['opening_minor'];
        $expected = $opening + $cash['cash_sales_minor'] - $cash['cash_returns_minor'] + $cash['cash_in_minor'] - $cash['cash_out_minor'];
        $variance = $counted - $expected;
        $needsApproval=abs($variance)>self::maxDifference();
        if ($needsApproval && (!current_user_can('sfm4u_pos_credit') || $approval === '')) {
            throw new DomainException('A manager must approve and explain the drawer variance.', 403);
        }
        $serial = (int) $this->register($merchant, $register, true)['last_z'] + 1;
        $closed = (string) $this->db->get_var('SELECT UTC_TIMESTAMP()');
        $snapshot = $cash + ['kind'=>'Z', 'number'=>sprintf('%s-Z-%06d', $register, $serial), 'currency'=>'BDT',
            'register'=>$register, 'session_id'=>$session, 'opened_at'=>$row['opened_at'], 'closed_at'=>$closed,
            'opened_by'=>(int) $row['opened_by'], 'closed_by'=>$actor, 'approval_by'=>$needsApproval ? $actor : null,
            'approval_note'=>$needsApproval ? $approval : '', 'opening_minor'=>$opening, 'expected_cash_minor'=>$expected,
            'counted_minor'=>$counted, 'variance_minor'=>$variance];
        $this->query($this->db->prepare('UPDATE %i SET closed_at=%s,z_number=%d,snapshot=%s WHERE id=%d AND closed_at IS NULL',
            $this->sessions, $closed, $serial, wp_json_encode($snapshot, JSON_THROW_ON_ERROR), $session));
        $this->query($this->db->prepare('UPDATE %i SET active_session=NULL,last_z=%d WHERE merchant=%d AND register_code=%s',
            $this->registers, $serial, $merchant, $register));
        return $snapshot;
    }

    public function status(int $merchant, string $register): array
    {
        $state = $this->register($merchant, $register, false);
        $row = $this->row($this->db->prepare(
            'SELECT snapshot FROM %i WHERE merchant=%d AND register_code=%s AND z_number IS NOT NULL ORDER BY z_number DESC LIMIT 1',
            $this->sessions, $merchant, $register
        ));
        return ['session_id'=>(int) ($state['active_session'] ?? 0),
            'last_z'=>$row ? json_decode($row['snapshot'], true, 32, JSON_THROW_ON_ERROR) : null];
    }

    public function xReport(int $merchant, string $register): array
    {
        if (!$this->db instanceof \SFM4U\Inventory\LossAwareWpdb) throw new RuntimeException('Stable register connection required.');
        $this->db->pinTransaction();
        try {
            $this->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $state = $this->register($merchant, $register, false);
            $session = (int) ($state['active_session'] ?? 0);
            if (!$session) throw new DomainException('Open the register before reading an X-report.', 409);
            $row = $this->row($this->db->prepare('SELECT opening_minor FROM %i WHERE id=%d', $this->sessions, $session));
            $cash = $this->cashTotals($merchant, $register, $session);
            $result = $cash + ['kind'=>'X', 'number'=>$register . '-X-' . $session, 'register'=>$register, 'currency'=>'BDT',
                'session_id'=>$session, 'opening_minor'=>(int) $row['opening_minor'],
                'expected_cash_minor'=>(int) $row['opening_minor'] + $cash['cash_sales_minor'] - $cash['cash_returns_minor'] + $cash['cash_in_minor'] - $cash['cash_out_minor']];
            $this->query('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try { $this->db->query('ROLLBACK'); } catch (\Throwable) {}
            throw $error;
        } finally { $this->db->unpinTransaction(); }
    }

    public function count(int $merchant,string $register,int $session,int $actor,int $counted,string $approval):array
    {
        $this->assertActive($merchant,$register,$session);
        $row=$this->row($this->db->prepare('SELECT opening_minor FROM %i WHERE id=%d FOR UPDATE',$this->sessions,$session));
        $cash=$this->cashTotals($merchant,$register,$session);
        $expected=(int)$row['opening_minor']+$cash['cash_sales_minor']-$cash['cash_returns_minor']+$cash['cash_in_minor']-$cash['cash_out_minor'];
        $variance=$counted-$expected;$needs=abs($variance)>self::maxDifference();
        if($needs && (!current_user_can('sfm4u_pos_credit') || $approval===''))throw new DomainException('A manager must approve and explain the drawer variance.',403);
        return ['kind'=>'MID_COUNT','register'=>$register,'session_id'=>$session,'actor_id'=>$actor,'counted_minor'=>$counted,
            'expected_cash_minor'=>$expected,'variance_minor'=>$variance,'approval_by'=>$needs?$actor:null,'approval_note'=>$needs?$approval:''];
    }

    private static function maxDifference():int
    {
        $value=get_option('sfm4u_pos_max_difference_minor','0');
        if((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,10}$/D',(string)$value) || (int)$value>1000000000)throw new DomainException('Invalid cash variance policy.',409);
        return (int)$value;
    }

    public function endedOn(int $merchant, string $register, string $day, string $end): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT snapshot FROM %i WHERE merchant=%d AND register_code=%s AND closed_at>=%s AND closed_at<%s ORDER BY z_number',
            $this->sessions, $merchant, $register, $day, $end
        ), ARRAY_A);
        if ($this->db->last_error !== '') {
            throw new RuntimeException('Daily register closings could not be read.');
        }
        return array_map(static fn(array $row): array => json_decode($row['snapshot'], true, 32, JSON_THROW_ON_ERROR), $rows);
    }

    public function openAtCutoff(int $merchant, string $register, string $end): int
    {
        $row = $this->row($this->db->prepare(
            'SELECT COUNT(*) AS sessions FROM %i WHERE merchant=%d AND register_code=%s AND opened_at<%s
             AND (closed_at IS NULL OR closed_at>=%s)', $this->sessions, $merchant, $register, $end, $end
        ));
        return (int) $row['sessions'];
    }

    public function beganBefore(int $merchant, string $register, string $end): bool
    {
        return $this->row($this->db->prepare(
            'SELECT id FROM %i WHERE merchant=%d AND register_code=%s AND opened_at<%s LIMIT 1',
            $this->sessions, $merchant, $register, $end
        )) !== null;
    }
}
