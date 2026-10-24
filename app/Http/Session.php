<?php
declare(strict_types=1);
namespace Corpsakademie\Http;

final class Session
{
    public bool $new = false;
    public bool $dirty = false;
    public function __construct(public string $token, public ?int $userId, public array $data, public int $absoluteExpiry) {}
    public function csrf(): string { return $this->data['csrf']; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; $this->dirty = true; }
    public function take(string $key): mixed
    {
        $value = $this->data[$key] ?? null;
        unset($this->data[$key]);
        $this->dirty = true;
        return $value;
    }
}
