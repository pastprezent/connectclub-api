<?php

namespace App\Tests\VideoRoom;

use App\Entity\Community\Community;
use App\Entity\Follow\Follow;
use App\Entity\User;
use App\Entity\VideoChat\VideoRoom;
use App\Message\AmplitudeEventStatisticsMessage;
use App\Tests\ApiTester;
use App\Tests\BaseCest;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Mockery;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Codeception\Util\HttpCode;

class LastParticipantLeaveSetsDoneAtCest extends BaseJitsiCest
{
    const COMMUNITY_NAME = 'adhoc_leave_done_at_room';

    public function testLastEndpointExpiredSetsDoneAtAndDropsFromOnline(ApiTester $I)
    {
        $mock = Mockery::spy(MessageBusInterface::class);
        $mock->shouldReceive('dispatch')
             ->andReturn(new Envelope(Mockery::mock(AmplitudeEventStatisticsMessage::class)));
        $I->mockService(MessageBusInterface::class, $mock);

        $I->loadFixtures(new class extends Fixture {
            public function load(ObjectManager $manager)
            {
                $main = $manager->getRepository('App:User')->findOneBy(['email' => BaseCest::MAIN_USER_EMAIL]);
                $alice = $manager->getRepository('App:User')->findOneBy(['email' => BaseCest::ALICE_USER_EMAIL]);

                $manager->persist(new Follow($alice, $main));
                $manager->persist(new Follow($main, $alice));

                $community = new Community($main, LastParticipantLeaveSetsDoneAtCest::COMMUNITY_NAME, 'Leave done_at');
                $community->videoRoom->type = VideoRoom::TYPE_NEW;
                $community->videoRoom->startedAt = time();
                $community->videoRoom->isPrivate = false;

                $manager->persist($community);
                $manager->flush();
            }
        }, true);

        /** @var VideoRoom $videoRoom */
        $videoRoom = $I->grabEntityFromRepository(VideoRoom::class, [
            'community' => ['name' => self::COMMUNITY_NAME],
        ]);

        $mainId = $I->grabFromRepository(User::class, 'id', ['email' => self::MAIN_USER_EMAIL]);

        $sid = $this->createMeetingForVideoRoom($I, $videoRoom);
        $endpointUuid = $this->createEndpoint($I, $videoRoom, $sid, $mainId);

        $I->sendPost('/v2/video-room/event', json_encode([
            'eventType' => 'ENDPOINT_EXPIRED',
            'conferenceGid' => $videoRoom->community->name,
            'conferenceId' => $sid,
            'endpointId' => (string) $mainId,
            'endpointUuid' => $endpointUuid,
        ]));
        $I->seeResponseCodeIs(HttpCode::OK);

        $doneAt = $I->grabFromRepository(VideoRoom::class, 'doneAt', [
            'community' => ['name' => self::COMMUNITY_NAME],
        ]);
        $I->assertNotNull($doneAt);

        $I->amBearerAuthenticated(self::ALICE_ACCESS_TOKEN);
        $I->sendGet('/v1/event/online');
        $I->seeResponseCodeIs(HttpCode::OK);

        $titles = $I->grabDataFromResponseByJsonPath('$.response.items[*].title');
        $I->assertFalse(in_array('Leave done_at', $titles, true));
    }
}
