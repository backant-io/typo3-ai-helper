<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Exception;

class LmStudioException extends \RuntimeException
{
    public const CODE_UNREACHABLE = 1714140001;
    public const CODE_NO_MODEL_LOADED = 1714140002;
    public const CODE_INVALID_RESPONSE = 1714140003;
    public const CODE_HTTP_ERROR = 1714140004;
}
