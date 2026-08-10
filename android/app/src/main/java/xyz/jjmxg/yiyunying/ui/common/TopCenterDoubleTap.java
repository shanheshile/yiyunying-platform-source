package xyz.jjmxg.yiyunying.ui.common;

import android.view.GestureDetector;
import android.view.MotionEvent;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.core.widget.NestedScrollView;
import androidx.recyclerview.widget.RecyclerView;

/**
 * Reserves the middle part of a top bar for the familiar "double tap to top"
 * gesture while preserving the bar's existing single-click action.
 */
public final class TopCenterDoubleTap {
    private static final float CENTER_START = 0.30f;
    private static final float CENTER_END = 0.70f;

    private TopCenterDoubleTap() { }

    public static void attach(@NonNull View topBar, @NonNull RecyclerView recycler) {
        attach(topBar, () -> scrollToTop(recycler));
    }

    public static void attach(@NonNull View topBar, @NonNull NestedScrollView scroll) {
        attach(topBar, () -> {
            scroll.stopNestedScroll();
            scroll.scrollTo(0, 0);
        });
    }

    public static void attach(@NonNull View topBar, @NonNull Runnable scrollToTop) {
        GestureDetector detector = new GestureDetector(topBar.getContext(),
            new GestureDetector.SimpleOnGestureListener() {
                @Override public boolean onDown(@NonNull MotionEvent event) {
                    return true;
                }

                @Override public boolean onSingleTapConfirmed(@NonNull MotionEvent event) {
                    // Toolbars such as the chat header already use a single tap to
                    // open conversation details. Delay that action until Android has
                    // ruled out a double tap, then dispatch it normally.
                    topBar.performClick();
                    return true;
                }

                @Override public boolean onDoubleTap(@NonNull MotionEvent event) {
                    scrollToTop.run();
                    return true;
                }
            });

        topBar.setOnTouchListener(new View.OnTouchListener() {
            private boolean trackingCenter;

            @Override public boolean onTouch(View view, MotionEvent event) {
                int action = event.getActionMasked();
                if (action == MotionEvent.ACTION_DOWN) {
                    float width = Math.max(1f, view.getWidth());
                    float fraction = event.getX() / width;
                    trackingCenter = fraction >= CENTER_START && fraction <= CENTER_END;
                }
                if (!trackingCenter) return false;
                detector.onTouchEvent(event);
                if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL) {
                    trackingCenter = false;
                }
                return true;
            }
        });
    }

    public static void scrollToTop(@NonNull RecyclerView recycler) {
        RecyclerView.Adapter<?> adapter = recycler.getAdapter();
        if (adapter == null || adapter.getItemCount() == 0) return;
        recycler.stopScroll();
        recycler.scrollToPosition(0);
    }
}
