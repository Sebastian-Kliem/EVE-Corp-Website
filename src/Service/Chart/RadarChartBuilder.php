<?php

namespace App\Service\Chart;

/**
 * Computes SVG coordinates for a radar (spider) chart, so templates only draw.
 */
class RadarChartBuilder
{
    private const RING_COUNT = 4;
    private const LABEL_OFFSET = 10;
    // Room for the axis labels left/right and above/below the plot
    private const HORIZONTAL_PADDING = 150;
    private const VERTICAL_PADDING = 30;

    /**
     * @param list<array{label: string, value: float}> $axes values in the same unit as $scaleMax
     */
    public function build(array $axes, float $scaleMax, float $radius = 150.0): array
    {
        $axisCount = count($axes);
        if ($axisCount < 3 || $scaleMax <= 0) {
            return ['width' => 0, 'height' => 0, 'centerX' => 0.0, 'centerY' => 0.0, 'axes' => [], 'rings' => [], 'valuePoints' => ''];
        }

        $center = [$radius + self::HORIZONTAL_PADDING, $radius + self::VERTICAL_PADDING];
        $chartAxes = [];
        $valuePoints = [];

        foreach ($axes as $index => $axis) {
            $angle = $this->_getAngle($index, $axisCount);
            $valueRatio = max(0.0, min(1.0, $axis['value'] / $scaleMax));
            $valuePoint = $this->_getPoint($center, $radius * $valueRatio, $angle);
            $valuePoints[] = $this->_formatPoint($valuePoint);

            $outerPoint = $this->_getPoint($center, $radius, $angle);
            $labelPoint = $this->_getPoint($center, $radius + self::LABEL_OFFSET, $angle);
            $chartAxes[] = [
                'label' => $axis['label'],
                'value' => $axis['value'],
                'x' => $outerPoint[0],
                'y' => $outerPoint[1],
                'labelX' => $labelPoint[0],
                'labelY' => $labelPoint[1],
                'labelAnchor' => $this->_getLabelAnchor($angle),
                'pointX' => $valuePoint[0],
                'pointY' => $valuePoint[1],
            ];
        }

        return [
            'width' => $center[0] * 2,
            'height' => $center[1] * 2,
            'centerX' => $center[0],
            'centerY' => $center[1],
            'axes' => $chartAxes,
            'rings' => $this->_buildRings($center, $radius, $axisCount, $scaleMax),
            'valuePoints' => implode(' ', $valuePoints),
        ];
    }

    private function _buildRings(array $center, float $radius, int $axisCount, float $scaleMax): array
    {
        $rings = [];
        for ($ringIndex = 1; $ringIndex <= self::RING_COUNT; $ringIndex++) {
            $ringRadius = $radius * $ringIndex / self::RING_COUNT;
            $ringPoints = [];
            for ($axisIndex = 0; $axisIndex < $axisCount; $axisIndex++) {
                $ringPoints[] = $this->_formatPoint($this->_getPoint($center, $ringRadius, $this->_getAngle($axisIndex, $axisCount)));
            }
            $rings[] = [
                'points' => implode(' ', $ringPoints),
                'value' => $scaleMax * $ringIndex / self::RING_COUNT,
                'labelY' => $center[1] - $ringRadius,
            ];
        }

        return $rings;
    }

    // First axis points straight up, then clockwise
    private function _getAngle(int $index, int $axisCount): float
    {
        return -M_PI / 2 + 2 * M_PI * $index / $axisCount;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function _getPoint(array $center, float $distance, float $angle): array
    {
        return [round($center[0] + $distance * cos($angle), 2), round($center[1] + $distance * sin($angle), 2)];
    }

    private function _formatPoint(array $point): string
    {
        return $point[0] . ',' . $point[1];
    }

    private function _getLabelAnchor(float $angle): string
    {
        $horizontal = cos($angle);
        if ($horizontal > 0.2) {
            return 'start';
        }
        if ($horizontal < -0.2) {
            return 'end';
        }

        return 'middle';
    }
}
