package xyz.jjmxg.yiyunying.ui.chat;

import org.junit.Test;

import static org.junit.Assert.assertEquals;

public final class RecentPhotoSuggestionPolicyTest {
    @Test public void firstChatOpenOnlyEstablishesBaseline() {
        assertEquals(
            RecentPhotoSuggestionPolicy.Decision.INITIALIZE,
            RecentPhotoSuggestionPolicy.decide(false, 0, 0, 100, 10)
        );
    }

    @Test public void firstPhotoAfterEmptyBaselineIsShown() {
        assertEquals(
            RecentPhotoSuggestionPolicy.Decision.SHOW,
            RecentPhotoSuggestionPolicy.decide(true, 0, 0, 100, 10)
        );
    }

    @Test public void samePhotoIsOnlyShownOnceGlobally() {
        assertEquals(
            RecentPhotoSuggestionPolicy.Decision.IGNORE,
            RecentPhotoSuggestionPolicy.decide(true, 100, 10, 100, 10)
        );
    }

    @Test public void secondPhotoInSameSecondIsStillNew() {
        assertEquals(
            RecentPhotoSuggestionPolicy.Decision.SHOW,
            RecentPhotoSuggestionPolicy.decide(true, 100, 10, 100, 11)
        );
    }

    @Test public void deletingLatestPhotoDoesNotRecommendAnOlderPhoto() {
        assertEquals(
            RecentPhotoSuggestionPolicy.Decision.IGNORE,
            RecentPhotoSuggestionPolicy.decide(true, 100, 10, 99, 9)
        );
    }

    @Test public void emptyAlbumDoesNotCreateARecommendation() {
        assertEquals(
            RecentPhotoSuggestionPolicy.Decision.IGNORE,
            RecentPhotoSuggestionPolicy.decide(true, 100, 10, 0, 0)
        );
    }
}
