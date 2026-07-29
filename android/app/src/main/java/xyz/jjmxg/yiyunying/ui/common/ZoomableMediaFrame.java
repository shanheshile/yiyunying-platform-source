package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.util.AttributeSet;
import android.view.GestureDetector;
import android.view.MotionEvent;
import android.view.ScaleGestureDetector;
import android.view.View;
import android.view.ViewConfiguration;
import android.widget.FrameLayout;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

public final class ZoomableMediaFrame extends FrameLayout {
    public interface PlaybackGestureListener {
        void onGestureStart();
        void onHorizontalSeek(float distanceFraction, boolean finished);
        void onVerticalAdjust(boolean leftSide, float distanceFraction, boolean finished);
    }

    private static final float MIN_SCALE = 1f;
    private static final float DOUBLE_TAP_SCALE = 2f;
    private static final float MAX_SCALE = 4f;

    private final ScaleGestureDetector scaleDetector;
    private final GestureDetector gestureDetector;
    private View zoomTarget;
    private Runnable doubleTapAction;
    private Runnable holdStartAction;
    private Runnable holdEndAction;
    private PlaybackGestureListener playbackGestureListener;
    private final int touchSlop;
    private boolean holding;
    private int playbackGestureDirection;
    private float playbackDownX;
    private float playbackDownY;
    private float scale = MIN_SCALE;
    private float translationX;
    private float translationY;

    public ZoomableMediaFrame(@NonNull Context context) { this(context, null); }

    public ZoomableMediaFrame(@NonNull Context context, @Nullable AttributeSet attrs) {
        super(context, attrs);
        setClickable(true);
        touchSlop = ViewConfiguration.get(context).getScaledTouchSlop();
        scaleDetector = new ScaleGestureDetector(context, new ScaleGestureDetector.SimpleOnScaleGestureListener() {
            @Override public boolean onScale(ScaleGestureDetector detector) {
                if (zoomTarget == null) return false;
                scale = clamp(scale * detector.getScaleFactor(), MIN_SCALE, MAX_SCALE);
                if (scale <= MIN_SCALE + 0.01f) { translationX = 0f; translationY = 0f; }
                apply(false);
                return true;
            }
        });
        gestureDetector = new GestureDetector(context, new GestureDetector.SimpleOnGestureListener() {
            @Override public boolean onDown(@NonNull MotionEvent event) { return zoomTarget != null; }

            @Override public boolean onDoubleTap(@NonNull MotionEvent event) {
                if (zoomTarget == null) return false;
                if (doubleTapAction != null) {
                    doubleTapAction.run();
                    return true;
                }
                scale = scale > MIN_SCALE + 0.1f ? MIN_SCALE : DOUBLE_TAP_SCALE;
                translationX = 0f;
                translationY = 0f;
                apply(true);
                return true;
            }

            @Override public void onLongPress(@NonNull MotionEvent event) {
                if (holdStartAction == null) return;
                holding = true;
                holdStartAction.run();
            }

            @Override public boolean onScroll(MotionEvent first, @NonNull MotionEvent current, float distanceX, float distanceY) {
                if (zoomTarget == null || scale <= MIN_SCALE + 0.01f) return false;
                translationX -= distanceX;
                translationY -= distanceY;
                clampTranslation();
                apply(false);
                return true;
            }
        });
    }

    public void setZoomTarget(@Nullable View target) {
        if (zoomTarget != target) reset();
        zoomTarget = target;
    }

    public void setDoubleTapAction(@Nullable Runnable action) { doubleTapAction = action; }

    public void setHoldActions(@Nullable Runnable start, @Nullable Runnable end) {
        holdStartAction = start;
        holdEndAction = end;
        holding = false;
    }

    public void setPlaybackGestureListener(@Nullable PlaybackGestureListener listener) {
        playbackGestureListener = listener;
        playbackGestureDirection = 0;
    }

    public void reset() {
        scale = MIN_SCALE;
        translationX = 0f;
        translationY = 0f;
        apply(false);
    }

    public boolean isZoomed() { return scale > MIN_SCALE + 0.01f; }

    @Override public boolean dispatchTouchEvent(MotionEvent event) {
        if (zoomTarget != null && zoomTarget.getVisibility() == View.VISIBLE) {
            int action = event.getActionMasked();
            if (action == MotionEvent.ACTION_DOWN) {
                playbackDownX = event.getX();
                playbackDownY = event.getY();
                playbackGestureDirection = 0;
                if (playbackGestureListener != null && getParent() != null) {
                    getParent().requestDisallowInterceptTouchEvent(true);
                }
            }
            scaleDetector.onTouchEvent(event);
            gestureDetector.onTouchEvent(event);
            if (playbackGestureListener != null && event.getPointerCount() == 1 && !isZoomed()) {
                float deltaX = event.getX() - playbackDownX;
                float deltaY = event.getY() - playbackDownY;
                if (action == MotionEvent.ACTION_MOVE && playbackGestureDirection == 0
                    && (Math.abs(deltaX) > touchSlop || Math.abs(deltaY) > touchSlop)) {
                    playbackGestureDirection = Math.abs(deltaX) > Math.abs(deltaY) * 1.12f ? 1 : 2;
                    playbackGestureListener.onGestureStart();
                }
                if (action == MotionEvent.ACTION_MOVE && playbackGestureDirection != 0) {
                    if (playbackGestureDirection == 1) {
                        playbackGestureListener.onHorizontalSeek(deltaX / Math.max(1f, getWidth()), false);
                    } else {
                        playbackGestureListener.onVerticalAdjust(playbackDownX < getWidth() / 2f,
                            -deltaY / Math.max(1f, getHeight()), false);
                    }
                    return true;
                }
                if ((action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL)
                    && playbackGestureDirection != 0) {
                    if (playbackGestureDirection == 1) {
                        playbackGestureListener.onHorizontalSeek(deltaX / Math.max(1f, getWidth()), true);
                    } else {
                        playbackGestureListener.onVerticalAdjust(playbackDownX < getWidth() / 2f,
                            -deltaY / Math.max(1f, getHeight()), true);
                    }
                    playbackGestureDirection = 0;
                    if (getParent() != null) getParent().requestDisallowInterceptTouchEvent(false);
                    return true;
                }
            }
            boolean keepGesture = event.getPointerCount() > 1 || isZoomed() || scaleDetector.isInProgress()
                || (playbackGestureListener != null && action != MotionEvent.ACTION_UP && action != MotionEvent.ACTION_CANCEL);
            if (getParent() != null) getParent().requestDisallowInterceptTouchEvent(keepGesture);
            if (action == MotionEvent.ACTION_UP || action == MotionEvent.ACTION_CANCEL) {
                if (holding) {
                    holding = false;
                    if (holdEndAction != null) holdEndAction.run();
                }
                if (!isZoomed() && getParent() != null) getParent().requestDisallowInterceptTouchEvent(false);
            }
        }
        return super.dispatchTouchEvent(event);
    }

    @Override public boolean onInterceptTouchEvent(MotionEvent event) {
        return zoomTarget != null && (playbackGestureDirection != 0 || event.getPointerCount() > 1
            || isZoomed() || scaleDetector.isInProgress());
    }

    @Override public boolean onTouchEvent(MotionEvent event) {
        if (zoomTarget == null) return super.onTouchEvent(event);
        scaleDetector.onTouchEvent(event);
        gestureDetector.onTouchEvent(event);
        if (event.getActionMasked() == MotionEvent.ACTION_UP && !scaleDetector.isInProgress()) performClick();
        return true;
    }

    @Override public boolean performClick() {
        super.performClick();
        return true;
    }

    private void apply(boolean animate) {
        if (zoomTarget == null) return;
        clampTranslation();
        if (animate) {
            zoomTarget.animate().scaleX(scale).scaleY(scale).translationX(translationX).translationY(translationY)
                .setDuration(180L).start();
        } else {
            zoomTarget.animate().cancel();
            zoomTarget.setScaleX(scale);
            zoomTarget.setScaleY(scale);
            zoomTarget.setTranslationX(translationX);
            zoomTarget.setTranslationY(translationY);
        }
    }

    private void clampTranslation() {
        if (zoomTarget == null || scale <= MIN_SCALE) { translationX = 0f; translationY = 0f; return; }
        float maxX = getWidth() * (scale - 1f) / 2f;
        float maxY = getHeight() * (scale - 1f) / 2f;
        translationX = clamp(translationX, -maxX, maxX);
        translationY = clamp(translationY, -maxY, maxY);
    }

    private static float clamp(float value, float minimum, float maximum) {
        return Math.max(minimum, Math.min(maximum, value));
    }
}
