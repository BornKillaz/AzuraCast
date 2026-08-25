<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Entity\Enums\PlaylistSources;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds a wall-clock scheduling layer after AutoDJ/queue sources have been built,
 * but before crossfade and live-DJ handling are applied.
 *
 * This lets an exact-start playlist pre-empt AutoDJ mid-track while preserving
 * the existing higher priority of a connected live DJ.
 */
final class ExactScheduleConfigWriter implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => [
                ['writeExactScheduleConfiguration', 29],
            ],
        ];
    }

    public function writeExactScheduleConfiguration(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $playlistVarNames = [];
        $exactScheduleSwitches = [];

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled || !ConfigWriter::shouldWritePlaylist($event, $playlist)) {
                continue;
            }

            $playlistVarName = ConfigWriter::getPlaylistVariableName($playlist);

            if (in_array($playlistVarName, $playlistVarNames, true)) {
                $playlistVarName .= '_' . $playlist->id;
            }
            $playlistVarNames[] = $playlistVarName;

            if (!$playlist->backendExactStartUsesLiquidsoap()) {
                continue;
            }

            // Playlist groups and request sources are resolved by AutoDJ and cannot
            // be selected as direct Liquidsoap sources.
            if (
                PlaylistSources::Playlists === $playlist->source
                || PlaylistSources::Requests === $playlist->source
            ) {
                continue;
            }

            foreach ($playlist->schedule_items as $scheduleItem) {
                $playTime = $this->getScheduledPlaylistPlayTime($event, $scheduleItem);

                $exactScheduleSwitches[] = $playlist->backendPlaySingleTrack()
                    ? '(predicate.at_most(1, {' . $playTime . '}), ' . $playlistVarName . ')'
                    : '({ ' . $playTime . ' }, ' . $playlistVarName . ')';
            }
        }

        if (empty($exactScheduleSwitches)) {
            return;
        }

        $event->appendLines(['# Exact Wall-Clock Schedule Switches']);

        // Keep the same switch-size limit used by the core ConfigWriter.
        foreach (array_chunk($exactScheduleSwitches, 168, true) as $switchChunk) {
            $switchChunk[] = '({true}, radio)';

            $event->appendLines(
                [
                    sprintf(
                        'radio = switch(id="exact_schedule_switch", track_sensitive=false, [ %s ])',
                        implode(', ', $switchChunk)
                    ),
                ]
            );
        }
    }

    /**
     * Build the same Liquidsoap time predicate used by AzuraCast's core playlist writer.
     * Kept local so the exact-start layer can be inserted after AutoDJ without changing
     * the ordering of the existing ConfigWriter subscriber.
     */
    private function getScheduledPlaylistPlayTime(
        WriteLiquidsoapConfiguration $event,
        StationSchedule $playlistSchedule
    ): string {
        $startTime = $playlistSchedule->start_time;
        $endTime = $playlistSchedule->end_time;

        if ($startTime > $endTime) {
            $playTimes = [
                ConfigWriter::formatTimeCode($startTime) . '-23h59m59s',
                '00h00m-' . ConfigWriter::formatTimeCode($endTime),
            ];

            $playlistScheduleDays = $playlistSchedule->days;
            if (!empty($playlistScheduleDays) && count($playlistScheduleDays) < 7) {
                $currentPlayDays = [];
                $nextPlayDays = [];

                foreach ($playlistScheduleDays as $day) {
                    $currentPlayDays[] = (($day === 7) ? '0' : $day) . 'w';

                    $day++;
                    if ($day > 7) {
                        $day = 1;
                    }
                    $nextPlayDays[] = (($day === 7) ? '0' : $day) . 'w';
                }

                $playTimes[0] = '(' . implode(' or ', $currentPlayDays) . ') and ' . $playTimes[0];
                $playTimes[1] = '(' . implode(' or ', $nextPlayDays) . ') and ' . $playTimes[1];
            }

            return '(' . implode(') or (', $playTimes) . ')';
        }

        $playTime = ConfigWriter::formatTimeCode($startTime)
            . '-'
            . ConfigWriter::formatTimeCode($endTime);

        $playlistScheduleDays = $playlistSchedule->days;
        if (!empty($playlistScheduleDays) && count($playlistScheduleDays) < 7) {
            $playDays = [];

            foreach ($playlistScheduleDays as $day) {
                $playDays[] = (($day === 7) ? '0' : $day) . 'w';
            }
            $playTime = '(' . implode(' or ', $playDays) . ') and ' . $playTime;
        }

        $startDate = $playlistSchedule->start_date;
        $endDate = $playlistSchedule->end_date;

        if (!empty($startDate) || !empty($endDate)) {
            $tzObject = $event->getStation()->getTimezoneObject();

            $customFunctionBody = [];
            $scheduleMethod = 'exact_schedule_' . $playlistSchedule->id . '_date_range';
            $customFunctionBody[] = 'def ' . $scheduleMethod . '() =';

            $conditions = [];

            if (!empty($startDate)) {
                $startDateObj = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tzObject);

                if (null !== $startDateObj) {
                    $startDateObj = $startDateObj->setTime(0, 0);
                    $customFunctionBody[] = '    # ' . $startDateObj->__toString();
                    $customFunctionBody[] = '    range_start = ' . $startDateObj->getTimestamp() . '.';
                    $conditions[] = 'range_start <= current_time';
                }
            }

            if (!empty($endDate)) {
                $endDateObj = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $tzObject);

                if (null !== $endDateObj) {
                    $endDateObj = $endDateObj->setTime(23, 59, 59);
                    $customFunctionBody[] = '    # ' . $endDateObj->__toString();
                    $customFunctionBody[] = '    range_end = ' . $endDateObj->getTimestamp() . '.';
                    $conditions[] = 'current_time <= range_end';
                }
            }

            $customFunctionBody[] = '    current_time = time()';
            $customFunctionBody[] = '    result = (' . implode(' and ', $conditions) . ')';
            $customFunctionBody[] = '    result';
            $customFunctionBody[] = 'end';
            $event->appendLines($customFunctionBody);

            $playTime = $scheduleMethod . '() and ' . $playTime;
        }

        return $playTime;
    }
}
