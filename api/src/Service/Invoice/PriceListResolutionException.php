<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

final class PriceListResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $priceListItemId = null,
    ) {
        parent::__construct($message);
    }
}
