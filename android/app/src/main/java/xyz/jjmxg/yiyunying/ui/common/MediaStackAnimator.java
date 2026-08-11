package xyz.jjmxg.yiyunying.ui.common;

import android.animation.Animator;
import android.animation.AnimatorListenerAdapter;
import android.animation.AnimatorSet;
import android.animation.ObjectAnimator;
import android.view.View;
import android.widget.FrameLayout;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/** Applies {@link MediaStackTransitionPolicy} poses to real Android card views. */
public final class MediaStackAnimator {
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
            private boolean completed;

            private void completeOnce() {
                if (completed) return;
                completed = true;
                completion.run();
            }

            @Override public void onAnimationEnd(Animator animation) { completeOnce(); }
            @Override public void onAnimationCancel(Animator animation) { completeOnce(); }
        });
        set.start();
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
