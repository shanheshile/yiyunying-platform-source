package xyz.jjmxg.yiyunying.domain.forum;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class ForumUnlockPolicyTest {
    @Test public void combinesPaymentAndScheduleWithoutAmbiguity() {
        assertEquals(ForumUnlockPolicy.FREE, ForumUnlockPolicy.from(false, false));
        assertEquals(ForumUnlockPolicy.PAID, ForumUnlockPolicy.from(true, false));
        assertEquals(ForumUnlockPolicy.SCHEDULED, ForumUnlockPolicy.from(false, true));
        assertEquals(ForumUnlockPolicy.PAID_OR_SCHEDULED, ForumUnlockPolicy.from(true, true));
    }

    @Test public void validatesOnlyInputsRequiredByTheSelectedMode() {
        assertTrue(ForumUnlockPolicy.valid(ForumUnlockPolicy.FREE, 0, ""));
        assertFalse(ForumUnlockPolicy.valid(ForumUnlockPolicy.PAID, 0, ""));
        assertFalse(ForumUnlockPolicy.valid(ForumUnlockPolicy.SCHEDULED, 0, ""));
        assertTrue(ForumUnlockPolicy.valid(ForumUnlockPolicy.PAID_OR_SCHEDULED, 2.5, "2026-08-11T10:00:00Z"));
    }

    @Test public void labelExplainsTheActualUnlockContract() {
        assertEquals("付费 2.5 余额", ForumUnlockPolicy.label(ForumUnlockPolicy.PAID, 2.5, ""));
        assertTrue(ForumUnlockPolicy.label(ForumUnlockPolicy.PAID_OR_SCHEDULED, 3, "8月12日 10:00").contains("到期解锁"));
        assertTrue(ForumUnlockPolicy.explanation(ForumUnlockPolicy.PAID_OR_SCHEDULED).contains("付费提前查看"));
        assertTrue(ForumUnlockPolicy.explanation(ForumUnlockPolicy.SCHEDULED).contains("自动"));
        assertTrue(ForumUnlockPolicy.explanation(ForumUnlockPolicy.FREE).contains("无需支付"));
    }
}
