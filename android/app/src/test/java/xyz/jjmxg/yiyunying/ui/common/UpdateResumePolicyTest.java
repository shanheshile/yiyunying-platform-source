package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;

import org.junit.Test;

public class UpdateResumePolicyTest {
    @Test public void acceptsOnlyExactPartialRange() {
        UpdateResumePolicy.Decision decision = UpdateResumePolicy.decide(
            128L, 1024L, 206, "bytes 128-1023/1024", 896L);
        assertEquals(UpdateResumePolicy.Action.APPEND, decision.action);
        assertEquals(128L, decision.start);
        assertEquals(1024L, decision.total);
    }

    @Test public void fullResponseRestartsInsteadOfAppending() {
        UpdateResumePolicy.Decision decision = UpdateResumePolicy.decide(
            128L, 1024L, 200, null, 1024L);
        assertEquals(UpdateResumePolicy.Action.RESTART, decision.action);
        assertEquals(0L, decision.start);
    }

    @Test public void completeLocalPartCanBeVerifiedAfterRangeNotSatisfiable() {
        UpdateResumePolicy.Decision decision = UpdateResumePolicy.decide(
            1024L, 1024L, 416, "bytes */1024", 0L);
        assertEquals(UpdateResumePolicy.Action.VERIFY_LOCAL, decision.action);
    }

    @Test public void incompleteRangeNotSatisfiableRequiresOneCleanRestart() {
        UpdateResumePolicy.Decision decision = UpdateResumePolicy.decide(
            512L, 1024L, 416, "bytes */1024", 0L);
        assertEquals(UpdateResumePolicy.Action.RESTART, decision.action);
        assertEquals(UpdateResumePolicy.Action.RESTART, UpdateResumePolicy.decide(
            1024L, 1024L, 416, "bytes */2048", 0L).action);
    }

    @Test public void mismatchedRangeOrTotalFailsClosed() {
        assertEquals(UpdateResumePolicy.Action.FAIL, UpdateResumePolicy.decide(
            128L, 1024L, 206, "bytes 0-895/1024", 896L).action);
        assertEquals(UpdateResumePolicy.Action.FAIL, UpdateResumePolicy.decide(
            128L, 1024L, 206, "bytes 128-1023/2048", 896L).action);
        assertEquals(UpdateResumePolicy.Action.FAIL, UpdateResumePolicy.decide(
            128L, 1024L, 206, "bytes 128-1023/1024", 895L).action);
    }

    @Test public void oversizedOrUnknownPartsAreNeverResumed() {
        assertEquals(0L, UpdateResumePolicy.resumableOffset(2048L, 1024L));
        assertEquals(0L, UpdateResumePolicy.resumableOffset(128L, 0L));
        assertEquals(128L, UpdateResumePolicy.resumableOffset(128L, 1024L));
    }
}
