<?php

declare(strict_types=1);

namespace AIArmada\Signals\Reports;

use AIArmada\Signals\Contracts\ReportInterface;
use InvalidArgumentException;

final class ReportRegistry
{
    /** @var array<string, ReportInterface> */
    private array $reports = [];

    public function register(ReportInterface $report): void
    {
        $this->reports[$report->type()] = $report;
    }

    public function get(string $type): ReportInterface
    {
        if (! isset($this->reports[$type])) {
            throw new InvalidArgumentException("Report [{$type}] is not registered.");
        }

        return $this->reports[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->reports[$type]);
    }

    /**
     * @return array<string, ReportInterface>
     */
    public function all(): array
    {
        return $this->reports;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->reports as $report) {
            $options[$report->type()] = $report->name();
        }

        return $options;
    }
}
