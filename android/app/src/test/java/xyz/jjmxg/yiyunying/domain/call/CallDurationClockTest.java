package xyz.jjmxg.yiyunying.domain.call;

import static org.junit.Assert.assertEquals;

import org.junit.Test;

public class CallDurationClockTest {
    @Test public void advancesFromServerDurationWithoutResettingOnFrequentPolls() {
        CallDurationClock clock = new CallDurationClock();
        clock.sync(12L, true, 1_000L);
        assertEquals(15L, clock.seconds(4_500L));

        clock.sync(13L, true, 4_500L);
        assertEquals(15L, clock.seconds(4_500L));
        assertEquals(17L, clock.seconds(6_500L));
    }

    @Test public void acceptsServerCorrectionOnlyWhenItMovesForward() {
        CallDurationClock clock = new CallDurationClock();
        clock.sync(4L, true, 1_000L);
        clock.sync(20L, true, 3_000L);

        assertEquals(20L, clock.seconds(3_000L));
        assertEquals(22L, clock.seconds(5_000L));
    }

    @Test public void preservesElapsedValueWhenUiMovesIntoPictureInPicture() {
        CallDurationClock clock = new CallDurationClock();
        clock.sync(9L, true, 2_000L);

        assertEquals(18L, clock.seconds(11_800L));
        assertEquals(19L, clock.seconds(12_100L));
    }

    @Test public void staleRingingPollDoesNotResetCallerClock() {
        CallDurationClock clock = new CallDurationClock();
        clock.sync(0L, true, 1_000L);
        clock.sync(0L, false, 2_200L);

        assertEquals(3L, clock.seconds(4_200L));
    }

    @Test public void stopFreezesFinalDurationAndResetStartsFreshCall() {
        CallDurationClock clock = new CallDurationClock();
        clock.sync(5L, true, 1_000L);
        clock.stop(6L, 4_000L);
        assertEquals(8L, clock.seconds(20_000L));

        clock.reset();
        assertEquals(0L, clock.seconds(20_000L));
    }
}
