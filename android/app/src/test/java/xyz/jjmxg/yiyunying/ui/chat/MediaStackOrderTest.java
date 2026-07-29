package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import java.util.HashSet;
import java.util.List;
import org.junit.Test;

public final class MediaStackOrderTest {
    @Test public void middleItemFillsLayersFromBothSides() {
        assertEquals(java.util.Arrays.asList(1, 2, 0), MediaStackOrder.resolve(3, 1, 3));
    }

    @Test public void edgeAndOverflowInputsAlwaysProduceValidUniqueIndexes() {
        int[] positions = {Integer.MIN_VALUE, -1, 0, 1, 2, 3, Integer.MAX_VALUE};
        for (int current : positions) {
            List<Integer> indexes = MediaStackOrder.resolve(3, current, 3);
            assertEquals(3, indexes.size());
            assertEquals(indexes.size(), new HashSet<>(indexes).size());
            for (int index : indexes) assertTrue(index >= 0 && index < 3);
        }
    }

    @Test public void emptyAndSingleMediaAreHandledWithoutLooping() {
        assertTrue(MediaStackOrder.resolve(0, 0, 3).isEmpty());
        assertEquals(java.util.Collections.singletonList(0), MediaStackOrder.resolve(1, 99, 3));
    }
}
