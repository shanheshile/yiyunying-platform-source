package xyz.jjmxg.yiyunying.ui.common;

import android.animation.Animator;
import android.animation.AnimatorListenerAdapter;
import android.animation.AnimatorSet;
import android.animation.ObjectAnimator;
import android.view.View;
import android.widget.FrameLayout;

import java.lang.ref.WeakReference;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.WeakHashMap;

/** Applies {@link MediaStackTransitionPolicy} poses to real Android card views. */
public final class MediaStackAnimator {
    private static final Map<FrameLayout, WeakReference<AnimatorSet>> ACTIVE = new WeakHashMap<>();

    public interface ViewFactory {
        View create(int itemIndex);
    }

    private MediaStackAnimator() { }

    public static void applyPose(View view, MediaStackTransitionPolicy.Pose pose, float density) {
        view.setTranslationX(pose.x);
        view.setTranslationY(pose.y);
        view.setScaleX(pose.scale);
        view.setScaleY(pose.scale);
        view.setRotation(pose.rotation);
        view.setAlpha(pose.alpha);
        view.setTranslationZ(pose.depth * Math.max(1f, density));
    }

    /** Applies one interactive drag frame without translating or fading the whole stage. */
    public static void applyDrag(
        FrameLayout stage,
        MediaStackTransitionPolicy.Transition transition,
        float progress,
        float dragTranslationX,
        ViewFactory factory
    ) {
        cancel(stage);
        Map<Integer, View> cards = indexedCards(stage);
        Set<Integer> activeItems = new HashSet<>();
        float density = stage.getResources().getDisplayMetrics().density;
        for (MediaStackDragPolicy.ItemFrame frame
            : MediaStackDragPolicy.frames(transition, progress, dragTranslationX)) {
            activeItems.add(frame.itemIndex);
            View card = cards.get(frame.itemIndex);
            if (card == null) {
                card = factory.create(frame.itemIndex);
                card.setTag(frame.itemIndex);
                stage.addView(card);
                cards.put(frame.itemIndex, card);
            }
            card.animate().cancel();
            applyPose(card, frame.pose, density);
        }
        // A direction reversal may leave the previous direction's transparent entering card in
        // the stage. Keep it inert and invisible until the completion render removes it.
        for (Map.Entry<Integer, View> entry : cards.entrySet()) {
            if (!activeItems.contains(entry.getKey())) {
                entry.getValue().animate().cancel();
                entry.getValue().setAlpha(0f);
                entry.getValue().setTranslationZ(0f);
            }
        }
        stage.invalidate();
    }

    /**
     * Animates every card to the neighbouring layer boundary. The container is
     * never faded, and the completion callback is invoked after all cards end.
     */
    public static void animate(
        FrameLayout stage,
        MediaStackTransitionPolicy.Transition transition,
        float carriedTranslationX,
        long durationMs,
        ViewFactory factory,
        Runnable completion
    ) {
        cancel(stage);
        Map<Integer, View> cards = indexedCards(stage);
        float density = stage.getResources().getDisplayMetrics().density;
        List<Animator> animators = new ArrayList<>();
        for (MediaStackTransitionPolicy.ItemTransition item : transition.items) {
            View card = cards.get(item.itemIndex);
            if (card == null) {
                card = factory.create(item.itemIndex);
                card.setTag(item.itemIndex);
                stage.addView(card);
                applyPose(card, item.start, density);
            } else if (Math.abs(carriedTranslationX) > 0.01f) {
                // Preserve the user's drag position while moving the translation
                // from the stage into each card before the stage is reset.
                card.setTranslationX(item.start.x + carriedTranslationX);
            }
            animators.add(ObjectAnimator.ofFloat(card, View.TRANSLATION_X,
                card.getTranslationX(), item.end.x));
            animators.add(ObjectAnimator.ofFloat(card, View.TRANSLATION_Y,
                card.getTranslationY(), item.end.y));
            animators.add(ObjectAnimator.ofFloat(card, View.SCALE_X,
                card.getScaleX(), item.end.scale));
            animators.add(ObjectAnimator.ofFloat(card, View.SCALE_Y,
                card.getScaleY(), item.end.scale));
            animators.add(ObjectAnimator.ofFloat(card, View.ROTATION,
                card.getRotation(), item.end.rotation));
            animators.add(ObjectAnimator.ofFloat(card, View.ALPHA,
                card.getAlpha(), item.end.alpha));
            animators.add(ObjectAnimator.ofFloat(card, View.TRANSLATION_Z,
                card.getTranslationZ(), item.end.depth * Math.max(1f, density)));
        }
        if (animators.isEmpty()) {
            completion.run();
            return;
        }
        AnimatorSet set = new AnimatorSet();
        set.playTogether(animators);
        set.setDuration(Math.max(1L, durationMs));
        set.addListener(new AnimatorListenerAdapter() {
            private boolean cancelled;

            @Override public void onAnimationCancel(Animator animation) {
                cancelled = true;
                removeIfCurrent(stage, set);
            }

            @Override public void onAnimationEnd(Animator animation) {
                removeIfCurrent(stage, set);
                // A recycled holder must not be repopulated by a stale end callback.
                if (!cancelled) completion.run();
            }
        });
        synchronized (ACTIVE) {
            ACTIVE.put(stage, new WeakReference<>(set));
        }
        set.start();
    }

    /** Stops an in-flight stack transition without running its stale completion callback. */
    public static void cancel(FrameLayout stage) {
        AnimatorSet set = null;
        synchronized (ACTIVE) {
            WeakReference<AnimatorSet> reference = ACTIVE.remove(stage);
            if (reference != null) set = reference.get();
        }
        if (set != null) set.cancel();
    }

    public static boolean isRunning(FrameLayout stage) {
        synchronized (ACTIVE) {
            WeakReference<AnimatorSet> reference = ACTIVE.get(stage);
            AnimatorSet set = reference == null ? null : reference.get();
            if (set == null) {
                ACTIVE.remove(stage);
                return false;
            }
            return set.isStarted() && !set.isPaused();
        }
    }

    private static void removeIfCurrent(FrameLayout stage, AnimatorSet candidate) {
        synchronized (ACTIVE) {
            WeakReference<AnimatorSet> reference = ACTIVE.get(stage);
            if (reference != null && reference.get() == candidate) ACTIVE.remove(stage);
        }
    }

    private static Map<Integer, View> indexedCards(FrameLayout stage) {
        Map<Integer, View> cards = new LinkedHashMap<>();
        for (int index = 0; index < stage.getChildCount(); index++) {
            View child = stage.getChildAt(index);
            if (child.getTag() instanceof Integer) cards.put((Integer) child.getTag(), child);
        }
        return cards;
    }
}
