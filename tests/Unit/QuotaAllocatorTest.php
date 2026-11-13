<?php

namespace Tests\Unit;

use App\Paquetes\P04_HorariosYDisponibilidad\CU07_GestionarHorariosMedicos\Servicios\QuotaAllocator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotaAllocatorTest extends TestCase
{
    public static function distributions(): array
    {
        return [
            'empate 50/50' => [5, 5000, 5000, 3, 2],
            '60/40 exacto' => [5, 6000, 4000, 3, 2],
            'empate 70/30' => [5, 7000, 3000, 4, 1],
            '35/65 mayor resto presencial' => [5, 3500, 6500, 2, 3],
            '20/80 exacto' => [5, 2000, 8000, 1, 4],
            'mayor resto online' => [5, 2500, 7500, 1, 4],
            'un cupo empatado' => [1, 5000, 5000, 1, 0],
            'un cupo mayor resto online' => [1, 3500, 6500, 0, 1],
            'sin cupos' => [0, 5000, 5000, 0, 0],
            'todo online' => [5, 0, 10000, 0, 5],
            'todo presencial' => [5, 10000, 0, 5, 0],
            'porcentajes decimales' => [5, 3333, 6667, 2, 3],
        ];
    }

    #[DataProvider('distributions')]
    public function test_largest_remainder_matches_the_agreed_examples(int $capacity, int $presential, int $online, int $expectedPresential, int $expectedOnline): void
    {
        $this->assertSame(['presential' => $expectedPresential, 'online' => $expectedOnline],
            (new QuotaAllocator)->distribuir($capacity, $presential, $online));
    }

    public function test_distribution_never_exceeds_capacity_and_each_result_stays_within_one_quota(): void
    {
        $allocator = new QuotaAllocator;
        foreach ([0, 1, 2, 5, 17, 48] as $capacity) {
            foreach ([0, 1, 137, 2000, 3333, 3500, 5000, 6500, 7000, 9999, 10000] as $presential) {
                $result = $allocator->distribuir($capacity, $presential, 10000 - $presential);
                $this->assertSame($capacity, array_sum($result));
                $this->assertGreaterThanOrEqual(0, $result['presential']);
                $this->assertGreaterThanOrEqual(0, $result['online']);
                $this->assertLessThan(1, abs($result['presential'] - $capacity * $presential / 10000));
                $this->assertLessThan(1, abs($result['online'] - $capacity * (10000 - $presential) / 10000));
            }
        }
    }

    public function test_invalid_percentages_are_rejected_instead_of_allocating_extra_capacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QuotaAllocator)->distribuir(5, 6000, 6000);
    }
}
