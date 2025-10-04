<?php

namespace AjayMahato\Esewa\Enums;

enum PaymentStatus: string
{
    case PENDING        = 'PENDING';
    case COMPLETE       = 'COMPLETE';
    case FULL_REFUND    = 'FULL_REFUND';
    case PARTIAL_REFUND = 'PARTIAL_REFUND';
    case AMBIGUOUS      = 'AMBIGUOUS';
    case NOT_FOUND      = 'NOT_FOUND';
    case CANCELED       = 'CANCELED';
}
