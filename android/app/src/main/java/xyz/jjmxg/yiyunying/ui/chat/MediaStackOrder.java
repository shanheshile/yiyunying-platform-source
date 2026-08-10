package xyz.jjmxg.yiyunying.ui.chat;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

import xyz.jjmxg.yiyunying.ui.common.MediaStackTransitionPolicy;

final class MediaStackOrder {
    private MediaStackOrder() { }

    static List<Integer> resolve(int mediaCount, int current, int maxLayers) {
        return MediaStackTransitionPolicy.order(mediaCount, current, maxLayers);
    }
}
