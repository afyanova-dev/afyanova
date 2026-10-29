<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Adapter;

final class EscPosReceiptFormatter
{
    private const ESC = "\x1b";

    private const GS = "\x1d";

    private string $buffer = '';

    private int $columns;

    public function __construct(int $paperWidthMm = 80)
    {
        // 80mm typically has 48 columns, 58mm typically has 32 columns
        $this->columns = $paperWidthMm <= 58 ? 32 : 48;
        $this->initialize();
    }

    public static function for58mm(): self
    {
        return new self(58);
    }

    public static function for80mm(): self
    {
        return new self(80);
    }

    public function initialize(): self
    {
        $this->buffer = self::ESC.'@'; // Initialize printer

        return $this;
    }

    public function alignLeft(): self
    {
        $this->buffer .= self::ESC.'a'.chr(0);

        return $this;
    }

    public function alignCenter(): self
    {
        $this->buffer .= self::ESC.'a'.chr(1);

        return $this;
    }

    public function alignRight(): self
    {
        $this->buffer .= self::ESC.'a'.chr(2);

        return $this;
    }

    public function setBold(bool $enable = true): self
    {
        $this->buffer .= self::ESC.'E'.chr($enable ? 1 : 0);

        return $this;
    }

    public function setDoubleSize(bool $enable = true): self
    {
        // GS ! n (0x11 = double height + width, 0x00 = normal)
        $this->buffer .= self::GS.'!'.chr($enable ? 0x11 : 0x00);

        return $this;
    }

    public function addLine(string $text = ''): self
    {
        $this->buffer .= $text."\n";

        return $this;
    }

    public function addHeader(string $facilityName, string $address = '', string $phone = ''): self
    {
        $this->alignCenter();
        $this->setBold(true);
        $this->setDoubleSize(true);
        $this->addLine($facilityName);
        $this->setDoubleSize(false);
        $this->setBold(false);

        if ($address !== '') {
            $this->addLine($address);
        }
        if ($phone !== '') {
            $this->addLine("Tel: {$phone}");
        }

        $this->alignLeft();
        $this->addDivider('=');

        return $this;
    }

    public function addDivider(string $char = '-'): self
    {
        $this->buffer .= str_repeat($char[0] ?? '-', $this->columns)."\n";

        return $this;
    }

    public function addKeyValue(string $key, string $value): self
    {
        $keyLen = mb_strlen($key);
        $valLen = mb_strlen($value);
        $spaces = max(1, $this->columns - $keyLen - $valLen);

        $this->buffer .= $key.str_repeat(' ', $spaces).$value."\n";

        return $this;
    }

    public function addItemRow(string $name, int|string $qty, string $unitPrice, string $total): self
    {
        // Format: [Item Name 20-30 chars]
        // Next line or columns: Qty x UnitPrice -> Total
        $this->addLine($name);

        $detail = " {$qty} x {$unitPrice}";
        $totalStr = $total;
        $spaces = max(1, $this->columns - mb_strlen($detail) - mb_strlen($totalStr));

        $this->buffer .= $detail.str_repeat(' ', $spaces).$totalStr."\n";

        return $this;
    }

    public function addTotal(string $label, string $amount): self
    {
        $this->addDivider('-');
        $this->setBold(true);
        $this->addKeyValue($label, $amount);
        $this->setBold(false);
        $this->addDivider('-');

        return $this;
    }

    public function cut(bool $feed = true): self
    {
        if ($feed) {
            $this->buffer .= "\n\n\n";
        }
        // GS V A n (partial cut with feed)
        $this->buffer .= self::GS.'V'."\x41\x03";

        return $this;
    }

    public function toBytes(): string
    {
        return $this->buffer;
    }

    public function toBase64(): string
    {
        return base64_encode($this->buffer);
    }
}
