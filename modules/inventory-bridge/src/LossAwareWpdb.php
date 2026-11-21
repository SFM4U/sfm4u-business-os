<?php
namespace SFM4U\Inventory;

use RuntimeException;

/** The journal must never continue an old transaction on a new server session. */
final class LossAwareWpdb extends \wpdb {
    public const API_VERSION = 1;

    private bool $pinned = false;
    private ?RuntimeException $transactionFailure = null;

    public function pinTransaction(): void {
        if ($this->pinned) throw new RuntimeException('Nested transaction is not supported.');
        $this->pinned = true;
    }

    public function unpinTransaction(): void {
        $this->pinned = false;
        $this->transactionFailure = null;
    }

    public function transactionFailure(): ?RuntimeException {
        return $this->transactionFailure;
    }

    public function query($query) {
        // Woo CRUD can catch a database exception and keep writing after InnoDB
        // has rolled back the transaction. Only explicit cleanup may proceed.
        if ($this->transactionFailure && $query !== 'ROLLBACK') {
            throw $this->transactionFailure;
        }
        try {
            $result = parent::query($query);
        } catch (\mysqli_sql_exception $error) {
            if (!$this->pinned) throw $error;
            throw $this->transactionFailure ??= new RuntimeException('Database session failed. Recover using the original request key.',503,$error);
        }
        if ($this->pinned && $result === false) {
            // Some drivers report a lost socket as 2013 without calling check_connection().
            throw $this->transactionFailure ??= new RuntimeException('Database statement failed. Recover using the original request key.',503);
        }
        return $result;
    }

    public function check_connection($allow_bail = true) {
        if ($this->pinned) {
            throw $this->transactionFailure ??= new RuntimeException('Database session lost. Recover using the original request key.',503);
        }
        return parent::check_connection($allow_bail);
    }
}
