<?php

namespace Pagasa\Sso;

/**
 * The profile the SSO releases for a signed-in user.
 *
 * Only guid is immutable (AD objectGUID). Every other field can change or be
 * recycled, so key local records on guid. Blank values arrive as null.
 */
class SsoUser
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $guid,
        public ?string $username,
        public ?string $name,
        public ?string $email,
        public ?string $department,
        public ?string $title,
        public ?string $employeeNumber,
        protected array $raw = []
    ) {
    }

    /**
     * @param  array<string, mixed>  $profile  the `user` object from the token response
     */
    public static function fromArray(array $profile): self
    {
        return new self(
            self::clean($profile['guid'] ?? null),
            self::clean($profile['username'] ?? null),
            self::clean($profile['name'] ?? null),
            self::clean($profile['email'] ?? null),
            self::clean($profile['department'] ?? null),
            self::clean($profile['title'] ?? null),
            self::clean($profile['employee_number'] ?? null),
            $profile
        );
    }

    /**
     * Read a profile field by its wire name (e.g. `employee_number`).
     */
    public function get(string $field): ?string
    {
        return self::clean($this->raw[$field] ?? null);
    }

    /**
     * The profile exactly as the SSO sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'guid' => $this->guid,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'department' => $this->department,
            'title' => $this->title,
            'employee_number' => $this->employeeNumber,
        ];
    }

    protected static function clean($value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
