<?php

declare(strict_types=1);

namespace Quant\Market;

enum Side: string
{
    case Buy = 'buy';
    case Sell = 'sell';
}
