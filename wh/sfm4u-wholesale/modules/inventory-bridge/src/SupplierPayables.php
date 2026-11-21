<?php
declare(strict_types=1);

namespace SFM4U\Inventory;

use DomainException;
use RuntimeException;
use SFM4U\Wholesale\Rule;

final class SupplierPayables
{
    private string $bills;
    private string $payments;
    private RequestJournal $journal;

    public function __construct(private \wpdb $db, private StockValuation $valuation)
    {
        $this->bills = $db->prefix . 'sfm4u_supplier_bills';
        $this->payments = $db->prefix . 'sfm4u_supplier_payments';
        $this->journal = new RequestJournal($db);
    }

    public function install(): array
    {
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->bills} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            supplier varchar(150) NOT NULL,
            reference varchar(64) NOT NULL,
            receipt char(36) DEFAULT NULL,
            currency char(3) NOT NULL,
            amount_minor bigint unsigned NOT NULL,
            issued_on date NOT NULL,
            due_on date NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY supplier_bill (supplier,reference),
            UNIQUE KEY receipt_bill (receipt),
            KEY aging_date (issued_on,due_on)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$this->payments} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            bill_id bigint unsigned NOT NULL,
            reference varchar(64) NOT NULL,
            amount_minor bigint unsigned NOT NULL,
            paid_on date NOT NULL,
            kind varchar(12) NOT NULL DEFAULT 'payment',
            PRIMARY KEY  (id),
            UNIQUE KEY bill_payment (bill_id,reference),
            KEY bill_date (bill_id,paid_on)
        ) ENGINE=InnoDB {$charset};");
        return [$this->bills, $this->payments];
    }

    public function billReceipt(string $key, int $po, string $receipt, string $reference, string $issued, string $due): array
    {
        $this->authorize();
        $this->validateBill($reference, $issued, $due);
        if (!wp_is_uuid($receipt, 4)) {
            throw new DomainException('Enter a valid goods receipt ID.');
        }
        return $this->execute($key, compact('po', 'receipt', 'reference', 'issued', 'due'), function () use ($po, $receipt, $reference, $issued, $due): array {
            $snapshot = $this->db->get_var($this->db->prepare('SELECT snapshot FROM %i WHERE id=%d FOR UPDATE',
                $this->db->prefix . 'sfm4u_supplier_orders', $po));
            $amount = $this->valuation->receiptCost($receipt, $po);
            if (!$snapshot || $amount < 1 || $this->receiptBilled($receipt)) {
                throw new DomainException('Receipt is unavailable or already billed.');
            }
            $order = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
            return $this->insertBill($order['supplier'], $reference, $amount, $issued, $due, $order['currency'], $receipt);
        });
    }

    public function bill(string $key, string $supplier, string $reference, int $amount, string $issued, string $due): array
    {
        $this->authorize();
        $this->validateBill($reference, $issued, $due);
        $supplier = trim(sanitize_text_field($supplier));
        if ($supplier === '' || strlen($supplier) > 150 || $amount < 1 || wc_get_price_decimals() !== 2) {
            throw new DomainException('Enter supplier, amount and a two-decimal store currency.');
        }
        $currency = get_woocommerce_currency();
        return $this->execute($key, compact('supplier', 'reference', 'amount', 'issued', 'due', 'currency'),
            fn(): array => $this->insertBill($supplier, $reference, $amount, $issued, $due, $currency, null));
    }

    public function recordPayment(string $key, int $id, string $reference, int $amount, string $date): array
    {
        return $this->recordSettlement($key,$id,$reference,$amount,$date,'payment');
    }

    public function recordCredit(string $key,int $id,string $reference,int $amount,string $date):array
    {
        return $this->recordSettlement($key,$id,$reference,$amount,$date,'credit');
    }

    private function recordSettlement(string $key,int $id,string $reference,int $amount,string $date,string $kind):array
    {
        $this->authorize();
        $this->reference($reference);
        $this->date($date);
        if ($amount < 1 || $date > gmdate('Y-m-d')) {
            throw new DomainException('Enter a positive payment with a current or past date.');
        }
        return $this->execute($key, compact('id', 'reference', 'amount', 'date')+($kind==='payment'?[]:['kind'=>$kind]), function () use ($id, $reference, $amount, $date, $kind): array {
            $bill = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE id=%d FOR UPDATE', $this->bills, $id), ARRAY_A);
            if (!$bill || $date < $bill['issued_on']) {
                throw new DomainException('Bill is unavailable or payment predates the bill.');
            }
            if ($this->db->get_var($this->db->prepare('SELECT id FROM %i WHERE bill_id=%d AND reference=%s',
                $this->payments, $id, $reference))) {
                throw new DomainException('Payment reference is already recorded for this bill.');
            }
            $paid = (int) $this->db->get_var($this->db->prepare('SELECT SUM(amount_minor) FROM %i WHERE bill_id=%d', $this->payments, $id));
            $open = (int) $bill['amount_minor'] - $paid;
            if ($amount > $open) {
                throw new DomainException('Payment exceeds the bill balance.');
            }
            if ($this->db->insert($this->payments, ['bill_id' => $id, 'reference' => $reference,
                'amount_minor' => $amount, 'paid_on' => $date, 'kind'=>$kind]) !== 1) {
                throw new RuntimeException('Could not record supplier payment.');
            }
            do_action('sfm4u_supplier_settlement_before_commit',$kind,$id);
            return ['bill_id' => $id, 'open_minor' => $open - $amount, 'status' => $kind==='credit'?($open===$amount?'credited':'partially_credited'):($open===$amount?'paid':'partially_paid')];
        });
    }

    public function aging(string $asOf): array
    {
        $this->authorize();
        $date = $this->date($asOf);
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT b.id,b.supplier,b.reference,b.currency,b.due_on,b.amount_minor,
                COALESCE((SELECT SUM(p.amount_minor) FROM %i p WHERE p.bill_id=b.id AND p.paid_on<=%s AND p.kind=%s),0) AS paid_minor,
                COALESCE((SELECT SUM(c.amount_minor) FROM %i c WHERE c.bill_id=b.id AND c.paid_on<=%s AND c.kind=%s),0) AS credit_minor
             FROM %i b WHERE b.issued_on<=%s ORDER BY b.due_on,b.id',
            $this->payments, $asOf, 'payment', $this->payments, $asOf, 'credit', $this->bills, $asOf
        ), ARRAY_A);
        if ($this->db->last_error) {
            throw new RuntimeException('Could not read AP ageing.');
        }
        $currencies = [];
        $openBills = [];
        foreach ($rows as $row) {
            $open = (int) $row['amount_minor'] - (int) $row['paid_minor'] - (int) $row['credit_minor'];
            if ($open === 0) {
                continue;
            }
            $days = intdiv($date->getTimestamp() - $this->date($row['due_on'])->getTimestamp(), DAY_IN_SECONDS);
            $bucket = match (true) {
                $days <= 30 => '0-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+',
            };
            $currencies[$row['currency']] ??= ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
            $currencies[$row['currency']][$bucket] += $open;
            $openBills[] = $row + ['open_minor' => $open, 'bucket' => $bucket];
        }
        return ['as_of' => $asOf, 'currencies' => $currencies, 'bills' => $openBills];
    }

    public function receiptBilled(string $receipt): bool
    {
        return (bool) $this->db->get_var($this->db->prepare('SELECT id FROM %i WHERE receipt=%s', $this->bills, $receipt));
    }

    private function insertBill(string $supplier, string $reference, int $amount, string $issued, string $due, string $currency, ?string $receipt): array
    {
        if ($this->db->get_var($this->db->prepare('SELECT id FROM %i WHERE supplier=%s AND reference=%s', $this->bills, $supplier, $reference))) {
            throw new DomainException('Bill reference is already recorded for this supplier.');
        }
        if ($this->db->insert($this->bills, ['supplier' => $supplier, 'reference' => $reference, 'receipt' => $receipt,
            'currency' => $currency, 'amount_minor' => $amount, 'issued_on' => $issued, 'due_on' => $due]) !== 1) {
            throw new RuntimeException('Could not record supplier bill.');
        }
        return ['bill_id' => $this->db->insert_id, 'open_minor' => $amount, 'status' => 'open'];
    }

    private function validateBill(string $reference, string $issued, string $due): void
    {
        $this->reference($reference);
        $this->date($issued);
        $this->date($due);
        if ($issued > gmdate('Y-m-d') || $due < $issued) {
            throw new DomainException('Due date must follow issue date, which cannot be in the future.');
        }
    }

    private function date(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            throw new DomainException('Use a valid date in YYYY-MM-DD format.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Use a valid date in YYYY-MM-DD format.');
        }
        return $date;
    }

    private function reference(string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{0,63}$/D', $value)) {
            throw new DomainException('Enter a valid supplier reference.');
        }
    }

    private function execute(string $key, array $intent, callable $callback): array
    {
        $this->journal->requirePinnedConnection();
        return $this->journal->execute(get_current_blog_id(), 'supplier-ap', $key, $intent,
            static fn(): array => ['response' => $callback(), 'movements' => []]);
    }

    private function authorize(): void
    {
        if (!current_user_can('manage_woocommerce') || !(\SFM4U\POS\Runtime::enabled() || in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true))) {
            throw new DomainException('Supplier AP is restricted to synthetic store managers.', 403);
        }
    }
}
