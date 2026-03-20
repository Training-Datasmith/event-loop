<?php

declare (strict_types=1);
namespace Revolt\Event_Loop;

use Revolt\Event_Loop\Internal\Closure_Helper;
final class Invalid_Callback_Error extends \Error
{
    public const E_NONNULL_RETURN = 1;
    public const E_INVALID_IDENTIFIER = 2;
    /**
     * MUST be thrown if any callback returns a non-null value.
     */
    public static function non_null_return(string $callback_id, \Closure $closure): self
    {
        return new self($callback_id, self::E_NONNULL_RETURN, 'Non-null return value received from callback ' . Closure_Helper::get_description($closure));
    }
    /**
     * MUST be thrown if any operation (except disable() and cancel()) is attempted with an invalid callback identifier.
     *
     * An invalid callback identifier is any identifier that is not yet emitted by the driver or cancelled by the user.
     */
    public static function invalid_identifier(string $callback_id): self
    {
        return new self($callback_id, self::E_INVALID_IDENTIFIER, 'Invalid callback identifier ' . $callback_id);
    }
    private readonly string $raw_message;
    /** @var array<string, string> */
    private array $info = [];
    /**
     * @param string $callbackId The callback identifier.
     * @param string $message The exception message.
     */
    private function __construct(private readonly string $callback_id, int $code, string $message)
    {
        parent::__construct($message, $code);
        $this->raw_message = $message;
    }
    /**
     * @return string The callback identifier.
     */
    public function get_callback_id(): string
    {
        return $this->callback_id;
    }
    public function add_info(string $key, string $message): void
    {
        $this->info[$key] = $message;
        $info = '';
        foreach ($this->info as $info_key => $info_message) {
            $info .= "\r\n\r\n" . $info_key . ': ' . $info_message;
        }
        $this->message = $this->raw_message . $info;
    }
}