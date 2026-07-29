package xyz.jjmxg.yiyunying.voice;

import static org.junit.Assert.assertTrue;
import static org.junit.Assert.assertEquals;

import org.junit.Test;

public final class VoiceCallEngineTest {
    @Test public void normalLensScoresBetterThanUltraWideOrTelephoto() {
        double normal = VoiceCallEngine.normalLensScore(5.76f, 4.25f);
        double ultraWide = VoiceCallEngine.normalLensScore(5.76f, 1.85f);
        double telephoto = VoiceCallEngine.normalLensScore(5.76f, 8.5f);
        assertTrue(normal < ultraWide);
        assertTrue(normal < telephoto);
    }

    @Test public void normalLensUsesTwentyEightMillimeterEquivalentTarget() {
        assertEquals(28d, VoiceCallEngine.equivalentFocalLengthMm(5.4f, 4.2f), 0.001d);
        assertEquals(0d, VoiceCallEngine.normalLensScore(5.4f, 4.2f), 0.001d);
    }

    @Test public void fullHdThirtyFpsIsPreferredOverLowResolutionOrFourK() {
        double fullHd = VoiceCallEngine.captureFormatScore(1920, 1080, 30);
        double hd = VoiceCallEngine.captureFormatScore(1280, 720, 30);
        double fourK = VoiceCallEngine.captureFormatScore(3840, 2160, 30);
        double lowFps = VoiceCallEngine.captureFormatScore(1920, 1080, 15);
        assertTrue(fullHd < hd);
        assertTrue(fullHd < fourK);
        assertTrue(fullHd < lowFps);
    }

    @Test public void fullHdSixtyFpsIsPreferredWhenTheCameraSupportsIt() {
        double sixty = VoiceCallEngine.captureFormatScore(1920, 1080, 60);
        double thirty = VoiceCallEngine.captureFormatScore(1920, 1080, 30);
        double oneTwenty = VoiceCallEngine.captureFormatScore(1920, 1080, 120);
        assertTrue(sixty < thirty);
        assertTrue(sixty < oneTwenty);
    }
}
