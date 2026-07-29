package xyz.jjmxg.yiyunying.ui.chat;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

final class MediaStackOrder {
    private MediaStackOrder() { }

    static List<Integer> resolve(int mediaCount, int current, int maxLayers) {
        if (mediaCount <= 0 || maxLayers <= 0) return Collections.emptyList();
        int limit = Math.min(mediaCount, maxLayers);
        int safeCurrent = Math.max(0, Math.min(mediaCount - 1, current));
        List<Integer> indexes = new ArrayList<>(limit);
        indexes.add(safeCurrent);
        for (int distance = 1; distance < mediaCount && indexes.size() < limit; distance++) {
            int after = safeCurrent + distance;
            if (after < mediaCount) indexes.add(after);
            if (indexes.size() >= limit) break;
            int before = safeCurrent - distance;
            if (before >= 0) indexes.add(before);
        }
        return indexes;
    }
}
