package xyz.jjmxg.yiyunying.ui.chat;

/** Pure labels/state rules for GIF replay and looping controls. */
final class AnimatedImagePlaybackPolicy {
    static final boolean DEFAULT_LOOP_ENABLED = true;

    private AnimatedImagePlaybackPolicy() { }

    static boolean toggled(boolean enabled) { return !enabled; }

    static String loopLabel(boolean enabled) { return enabled ? "循环开" : "循环关"; }

    static String loopDescription(boolean enabled) {
        return enabled ? "动图循环播放已开启，点击关闭" : "动图循环播放已关闭，点击开启";
    }

    static String replayDescription() { return "从第一帧重新播放动图"; }
}
