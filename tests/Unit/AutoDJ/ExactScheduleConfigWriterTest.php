<?php

declare(strict_types=1);

namespace Unit\AutoDJ;

use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Backend\Liquidsoap\ExactScheduleConfigWriter;
use App\Service\PlaylistConfiguration\Schema\PlaylistConfigurationSchema;
use App\Tests\AutoDJ\DumpLoader;
use App\Tests\AutoDJ\InMemoryAutoDjHarnessFactory;
use App\Tests\AutoDJ\Scenario\Enums\ScenarioMode;
use App\Tests\AutoDJ\Scenario\ScenarioCase;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;

/**
 * @phpstan-import-type PlaylistConfigurationDump from PlaylistConfigurationSchema
 * @phpstan-import-type ProviderRow from DumpLoader
 */
final class ExactScheduleConfigWriterTest extends Unit
{
    /**
     * @return array<string, ProviderRow>
     */
    public static function caseProvider(): array
    {
        return array_filter(
            DumpLoader::providerForMode(ScenarioMode::InMemory),
            static fn(array $row, string $label): bool => str_starts_with($label, 'exact-start-playlist ::'),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param PlaylistConfigurationDump $dump
     */
    #[DataProvider('caseProvider')]
    public function testExactScheduleConfiguration(
        array $dump,
        ScenarioCase $case,
        ?string $description = null
    ): void {
        $harness = (new InMemoryAutoDjHarnessFactory())->create($dump, $case->runtime);
        $event = new WriteLiquidsoapConfiguration(
            $harness->entities->station,
            forEditing: false,
            writeToDisk: false
        );

        (new ExactScheduleConfigWriter())->writeExactScheduleConfiguration($event);

        $config = $event->buildConfiguration();

        self::assertStringContainsString('# Exact Wall-Clock Schedule Switches', $config);
        self::assertStringContainsString('track_sensitive=false', $config);
        self::assertStringContainsString('12h0m-15h0m', $config);
        self::assertStringContainsString('playlist_exact_scheduled_program', $config);
    }

    public function testSubscriberRunsAfterPlaylistWriterAndBeforeCrossfade(): void
    {
        self::assertSame(
            [
                WriteLiquidsoapConfiguration::class => [
                    ['writeExactScheduleConfiguration', 29],
                ],
            ],
            ExactScheduleConfigWriter::getSubscribedEvents()
        );
    }
}
