<?php

namespace SecucardConnect\Product\Smart;


use SecucardConnect\Client\ProductService;
use SecucardConnect\Product\Smart\Model\Transaction;

class TransactionsService extends ProductService
{

    /**
     * Starting/Executing a transaction.
     *
     * @param string $transactionId The transaction id.
     * @param string $type The transaction type like "auto" or "cash".
     * @return Transaction The started transaction.
     */
    public function start($transactionId, $type)
    {
        return $this->execute($transactionId, 'start', $type, null, Transaction::class);
    }

    /**
     * Cancel an existing transaction.
     * @param string $transactionId The transaction id.
     * @return bool True if successful false else.
     */
    public function cancel($transactionId)
    {
        $res = $this->execute($transactionId, 'cancel', null, 'array');
        return (bool)$res['result'];

    }
}