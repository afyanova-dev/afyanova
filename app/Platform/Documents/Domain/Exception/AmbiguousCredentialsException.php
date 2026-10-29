<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Exception;

use RuntimeException;

final class AmbiguousCredentialsException extends RuntimeException {}
