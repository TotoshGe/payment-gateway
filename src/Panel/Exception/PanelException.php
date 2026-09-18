<?php

declare(strict_types=1);

namespace App\Panel\Exception;

/** Base for all panel-driver errors (HTTP failure, malformed response, rejected call). */
class PanelException extends \RuntimeException
{
}
