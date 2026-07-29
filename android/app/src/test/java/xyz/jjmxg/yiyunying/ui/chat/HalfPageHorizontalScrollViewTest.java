package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;

import android.app.Activity;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewConfiguration;
import android.widget.LinearLayout;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.shadows.ShadowLooper;

import java.util.concurrent.atomic.AtomicInteger;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class HalfPageHorizontalScrollViewTest {
    @Test public void tapOnSecondPageReachesActionTile() {
        Activity activity = Robolectric.buildActivity(Activity.class).setup().get();
        HalfPageHorizontalScrollView pager = new HalfPageHorizontalScrollView(activity);
        LinearLayout pages = new LinearLayout(activity);
        pages.setOrientation(LinearLayout.HORIZONTAL);
        activity.setContentView(pager);

        View firstPage = new View(activity);
        View secondPageAction = new View(activity);
        pages.addView(firstPage, new LinearLayout.LayoutParams(600, 300));
        pages.addView(secondPageAction, new LinearLayout.LayoutParams(600, 300));
        pager.addView(pages, new LinearLayout.LayoutParams(1200, 300));

        pager.measure(
            View.MeasureSpec.makeMeasureSpec(600, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(300, View.MeasureSpec.EXACTLY));
        pager.layout(0, 0, 600, 300);
        pager.scrollTo(600, 0);

        assertEquals(600, pager.getScrollX());
        assertEquals(600, secondPageAction.getLeft());
        assertEquals(1200, secondPageAction.getRight());

        AtomicInteger clicks = new AtomicInteger();
        secondPageAction.setOnClickListener(view -> clicks.incrementAndGet());
        long now = android.os.SystemClock.uptimeMillis();
        pager.dispatchTouchEvent(MotionEvent.obtain(now, now, MotionEvent.ACTION_DOWN, 300f, 150f, 0));
        pager.dispatchTouchEvent(MotionEvent.obtain(now, now + 40L, MotionEvent.ACTION_UP, 300f, 150f, 0));
        ShadowLooper.runUiThreadTasksIncludingDelayedTasks();

        assertEquals(1, clicks.get());
    }

    @Test public void slightFingerDriftStillClicksActionExactlyOnce() {
        Activity activity = Robolectric.buildActivity(Activity.class).setup().get();
        HalfPageHorizontalScrollView pager = new HalfPageHorizontalScrollView(activity);
        LinearLayout pages = new LinearLayout(activity);
        View action = new View(activity);
        pages.addView(action, new LinearLayout.LayoutParams(600, 300));
        pager.addView(pages, new LinearLayout.LayoutParams(600, 300));
        activity.setContentView(pager);
        pager.measure(
            View.MeasureSpec.makeMeasureSpec(600, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(300, View.MeasureSpec.EXACTLY));
        pager.layout(0, 0, 600, 300);

        AtomicInteger clicks = new AtomicInteger();
        action.setOnClickListener(view -> clicks.incrementAndGet());
        float drift = ViewConfiguration.get(activity).getScaledTouchSlop() + 1f;
        long now = android.os.SystemClock.uptimeMillis();
        pager.dispatchTouchEvent(MotionEvent.obtain(now, now, MotionEvent.ACTION_DOWN, 240f, 150f, 0));
        pager.dispatchTouchEvent(MotionEvent.obtain(now, now + 20L, MotionEvent.ACTION_MOVE, 240f + drift, 151f, 0));
        pager.dispatchTouchEvent(MotionEvent.obtain(now, now + 45L, MotionEvent.ACTION_UP, 240f + drift, 151f, 0));
        ShadowLooper.runUiThreadTasksIncludingDelayedTasks();

        assertEquals(1, clicks.get());
    }
}
