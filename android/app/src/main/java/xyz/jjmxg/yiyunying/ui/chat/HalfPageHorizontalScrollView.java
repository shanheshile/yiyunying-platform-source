package xyz.jjmxg.yiyunying.ui.chat;

import android.content.Context;
import android.util.AttributeSet;
import android.view.MotionEvent;
import android.view.VelocityTracker;
import android.view.ViewConfiguration;
import android.widget.HorizontalScrollView;

/** A two-page horizontal scroller that follows the finger and settles after a short deliberate swipe. */
public final class HalfPageHorizontalScrollView extends HorizontalScrollView {
    private final int minimumFlingVelocity;
    private final int touchSlop;
    private final int horizontalDragSlop;
    private VelocityTracker velocityTracker;
    private int pageCount = 2;
    private int gestureStartPage;
    private boolean flingHandled;
    private boolean horizontalDrag;
    private float downX;
    private float downY;

    public HalfPageHorizontalScrollView(Context context) {
        this(context, null);
    }

    public HalfPageHorizontalScrollView(Context context, AttributeSet attrs) {
        this(context, attrs, 0);
    }

    public HalfPageHorizontalScrollView(Context context, AttributeSet attrs, int defStyleAttr) {
        super(context, attrs, defStyleAttr);
        ViewConfiguration configuration = ViewConfiguration.get(context);
        minimumFlingVelocity = configuration.getScaledMinimumFlingVelocity();
        touchSlop = configuration.getScaledTouchSlop();
        // A finger rarely lands perfectly still. Keep the whole action tile clickable even when
        // the press drifts just beyond the platform touch slop; an intentional page swipe still
        // starts after a short 12dp movement and keeps its velocity-based snap behaviour.
        horizontalDragSlop = Math.max(touchSlop * 2,
            Math.round(12f * getResources().getDisplayMetrics().density));
        setSmoothScrollingEnabled(true);
    }

    public void setPageCount(int pageCount) {
        this.pageCount = Math.max(1, pageCount);
    }

    public void settleToPage(int page) {
        int width = Math.max(1, getWidth());
        int boundedPage = Math.max(0, Math.min(pageCount - 1, page));
        smoothScrollTo(boundedPage * width, 0);
    }

    @Override public boolean dispatchTouchEvent(MotionEvent event) {
        int action = event.getActionMasked();
        if (action == MotionEvent.ACTION_DOWN) {
            flingHandled = false;
            horizontalDrag = false;
            downX = event.getX();
            downY = event.getY();
            gestureStartPage = nearestPage(getScrollX());
            recycleVelocityTracker();
            velocityTracker = VelocityTracker.obtain();
        } else if (action == MotionEvent.ACTION_MOVE && !horizontalDrag) {
            float distanceX = Math.abs(event.getX() - downX);
            float distanceY = Math.abs(event.getY() - downY);
            horizontalDrag = distanceX > horizontalDragSlop
                && distanceX > Math.max(distanceY * 1.25f, touchSlop);
        }
        if (velocityTracker != null) velocityTracker.addMovement(event);
        boolean handled = super.dispatchTouchEvent(event);
        if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL) {
            // A tap belongs to the child action tile. Settling a page after every ACTION_UP
            // causes HorizontalScrollView to cancel second-page clicks on some Android builds.
            if (horizontalDrag && !flingHandled) {
                float velocityX = 0f;
                if (velocityTracker != null && action == MotionEvent.ACTION_UP) {
                    velocityTracker.computeCurrentVelocity(1000);
                    // Finger velocity is opposite to content velocity.
                    velocityX = -velocityTracker.getXVelocity();
                }
                settleFromGesture(velocityX);
            }
            recycleVelocityTracker();
            horizontalDrag = false;
        }
        return handled;
    }

    @Override public boolean onInterceptTouchEvent(MotionEvent event) {
        int action = event.getActionMasked();
        if (action == MotionEvent.ACTION_DOWN) {
            // Let HorizontalScrollView initialise its own gesture bookkeeping, while preserving
            // the initial press for the action tile below it.
            super.onInterceptTouchEvent(event);
            return false;
        }
        if (action == MotionEvent.ACTION_MOVE && !horizontalDrag) {
            // Small finger drift is still a tap. Calling the platform implementation here would
            // cancel child clicks at the much smaller system touch-slop threshold.
            return false;
        }
        if (horizontalDrag) return super.onInterceptTouchEvent(event);
        return false;
    }

    @Override public void fling(int velocityX) {
        if (velocityX == 0) {
            return;
        }
        horizontalDrag = true;
        flingHandled = true;
        settleFromGesture(velocityX);
    }

    private void settleFromGesture(float velocityX) {
        int target = PageSnapPolicy.targetPage(
            gestureStartPage,
            getScrollX(),
            Math.max(1, getWidth()),
            velocityX,
            minimumFlingVelocity,
            pageCount);
        postOnAnimation(() -> settleToPage(target));
    }

    private int nearestPage(int scrollX) {
        return PageSnapPolicy.targetPage(0, scrollX, Math.max(1, getWidth()), 0f,
            Float.MAX_VALUE, pageCount);
    }

    private void recycleVelocityTracker() {
        if (velocityTracker == null) return;
        velocityTracker.recycle();
        velocityTracker = null;
    }

    @Override protected void onDetachedFromWindow() {
        recycleVelocityTracker();
        super.onDetachedFromWindow();
    }
}
