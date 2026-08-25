<?php

declare(strict_types=1);

namespace Unit\AutoDJ;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Radio\Backend\Liquidsoap\ExactScheduleConfigWriter;
use App\Service\PlaylistConfiguration\Schema\PlaylistConfigurationSchema;
use App\Tests\AutoDJ\DumpLoader;
use App\Tests\AutoDJ\InMemoryAutoDjHarness;
use App\Tests\AutoDJ\InMemoryAutoDjHarnessFactory;
use App\Tests\AutoDJ\Scenario\Enums\ScenarioMode;
use App\Tests\AutoDJ\Scenario\ScenarioCase;
use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use ReflectionClass;

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

    public function testSubscriberRunsAfterPlaylistWriterAndBeforeCrossfadeAndLive(): void
    {
        $exactListeners = ExactScheduleConfigWriter::getSubscribedEvents()[WriteLiquidsoapConfiguration::class];
        $exactPriority = $exactListeners[0][1];

        $corePriorities = [];
        foreach (ConfigWriter::getSubscribedEvents()[WriteLiquidsoapConfiguration::class] as $listener) {
            $corePriorities[$listener[0]] = $listener[1];
        }

        self::assertSame('writeExactScheduleConfiguration', $exactListeners[0][0]);
        self::assertLessThan($corePriorities['writePlaylistConfiguration'], $exactPriority);
        self::assertGreaterThan($corePriorities['writeCrossfadeConfiguration'], $exactPriority);
        self::assertGreaterThan($corePriorities['writeHarborConfiguration'], $exactPriority);
    }

    public function testLiquidsoapExactStartDoesNotAlsoUseLegacyScheduleSwitch(): void
    {
        $harness = $this->getHarness();
        $event = new WriteLiquidsoapConfiguration(
            $harness->entities->station,
            forEditing: true,
            writeToDisk: false
        );

        /** @var ConfigWriter $coreWriter */
        $coreWriter = (new ReflectionClass(ConfigWriter::class))->newInstanceWithoutConstructor();
        $coreWriter->writePlaylistConfiguration($event);
        (new ExactScheduleConfigWriter())->writeExactScheduleConfiguration($event);

        $config = $event->buildConfiguration();

        self::assertStringNotContainsString('# Interrupting Schedule Switches', $config);

        $interruptingQueuePosition = strpos($config, 'id="interrupting_fallback"');
        $exactSwitchPosition = strpos($config, 'id="exact_schedule_switch"');

        self::assertNotFalse($interruptingQueuePosition);
        self::assertNotFalse($exactSwitchPosition);
        self::assertGreaterThan($interruptingQueuePosition, $exactSwitchPosition);
    }

    public function testUnsupportedSourceFallsBackToInterruptingAutoDj(): void
    {
        $harness = $this->getHarness();
        $playlist = $harness->entities->playlistForRef('exact');
        $playlist->source = PlaylistSources::Requests;

        self::assertFalse($playlist->backendExactStartUsesLiquidsoap());
        self::assertTrue($playlist->isPlayable(true));
    }

    public function testNonBlockPlaylistTypeFallsBackToInterruptingAutoDj(): void
    {
        $harness = $this->getHarness();
        $playlist = $harness->entities->playlistForRef('exact');
        $playlist->type = PlaylistTypes::OncePerHour;

        self::assertFalse($playlist->backendExactStartUsesLiquidsoap());
        self::assertTrue($playlist->isPlayable(true));
    }

    public function testLoopOnceFallsBackToInterruptingAutoDj(): void
    {
        $harness = $this->getHarness();
        $playlist = $harness->entities->playlistForRef('exact');

        foreach ($playlist->schedule_items as $scheduleItem) {
            $scheduleItem->loop_once = true;
            break;
        }

        self::assertFalse($playlist->backendExactStartUsesLiquidsoap());
        self::assertTrue($playlist->isPlayable(true));
    }

    public function testSingleTrackIsPreservedByExactScheduleSwitch(): void
    {
        $harness = $this->getHarness();
        $playlist = $harness->entities->playlistForRef('exact');
        $playlist->backend_options = ['exact_start', 'single_track'];

        $event = new WriteLiquidsoapConfiguration(
            $harness->entities->station,
            forEditing: false,
            writeToDisk: false
        );

        (new ExactScheduleConfigWriter())->writeExactScheduleConfiguration($event);

        self::assertStringContainsString(
            '(predicate.at_most(1, {12h0m-15h0m}), playlist_exact_scheduled_program)',
            $event->buildConfiguration()
        );
    }

    private function getHarness(): InMemoryAutoDjHarness
    {
        $row = array_values(self::caseProvider())[0];

        return (new InMemoryAutoDjHarnessFactory())->create(
            $row['dump'],
            $row['case']->runtime
        );
    }
}
