<?php

namespace App\Command;

use App\Entity\VideoChat\VideoMeeting;
use App\Entity\VideoChat\VideoMeetingParticipant;
use App\Entity\VideoChat\VideoRoom;
use App\Repository\VideoChat\VideoMeetingRepository;
use App\Repository\VideoChat\VideoRoomRepository;
use App\Service\EventLogManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Safety net: type=new rooms that still look "live" (started_at set, done_at null)
 * but have no online participants — e.g. CONFERENCE_EXPIRED webhook missed after
 * ENDPOINT_EXPIRED already closed everyone — get done_at so «Идёт сейчас» drops them.
 *
 * Skips rooms that never recorded any participant (webhook fully broken + started_at
 * set at create/token): those need JVB_CONFERENCE_NOTIFICATION_URL / SQL unstick.
 */
class CloseEmptyOnlineVideoRoomsCommand extends Command
{
    protected static $defaultName = 'CloseEmptyOnlineVideoRoomsCommand';
    protected static $defaultDescription = 'Set done_at on empty type=new online rooms';

    private VideoRoomRepository $videoRoomRepository;
    private VideoMeetingRepository $videoMeetingRepository;
    private EventLogManager $eventLogManager;

    public function __construct(
        VideoRoomRepository $videoRoomRepository,
        VideoMeetingRepository $videoMeetingRepository,
        EventLogManager $eventLogManager
    ) {
        parent::__construct(self::$defaultName);

        $this->videoRoomRepository = $videoRoomRepository;
        $this->videoMeetingRepository = $videoMeetingRepository;
        $this->eventLogManager = $eventLogManager;
    }

    protected function configure(): void
    {
        $this->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var VideoRoom[] $rooms */
        $rooms = $this->videoRoomRepository->createQueryBuilder('vr')
            ->andWhere('vr.type = :type')
            ->andWhere('vr.startedAt IS NOT NULL')
            ->andWhere('vr.doneAt IS NULL')
            ->andWhere('vr.alwaysOnline = false')
            ->setParameter('type', VideoRoom::TYPE_NEW)
            ->getQuery()
            ->getResult();

        $closed = 0;

        foreach ($rooms as $videoRoom) {
            $hadParticipant = false;
            $hasOnline = false;

            foreach ($videoRoom->meetings as $meeting) {
                /** @var VideoMeeting $meeting */
                foreach ($meeting->participants as $participant) {
                    /** @var VideoMeetingParticipant $participant */
                    $hadParticipant = true;
                    if ($participant->endTime === null) {
                        $hasOnline = true;
                        break 2;
                    }
                }
            }

            if (!$hadParticipant || $hasOnline) {
                continue;
            }

            $videoRoom->doneAt = time();
            $this->videoRoomRepository->save($videoRoom);

            foreach ($videoRoom->meetings as $meeting) {
                if ($meeting->endTime === null) {
                    $meeting->endTime = time();
                    $this->videoMeetingRepository->save($meeting);
                }
            }

            $this->eventLogManager->logEvent($videoRoom, 'done_at_set_by_close_empty_online_rooms');
            ++$closed;
            $io->writeln(sprintf('Closed empty room %s', $videoRoom->community->name ?? $videoRoom->id));
        }

        $io->success(sprintf('Closed %d empty online room(s)', $closed));

        return Command::SUCCESS;
    }
}
