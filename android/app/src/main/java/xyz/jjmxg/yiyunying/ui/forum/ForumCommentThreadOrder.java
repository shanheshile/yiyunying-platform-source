package xyz.jjmxg.yiyunying.ui.forum;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

/** Keeps every reply directly below the top-level comment that owns it. */
final class ForumCommentThreadOrder {
    private ForumCommentThreadOrder() {}

    static final class CommentRef {
        final int sourceIndex;
        final long id;
        final long parentId;
        final long rootId;

        CommentRef(int sourceIndex, long id, long parentId, long rootId) {
            this.sourceIndex = sourceIndex;
            this.id = id;
            this.parentId = parentId;
            this.rootId = rootId;
        }
    }

    static List<Integer> orderedIndexes(List<CommentRef> source) {
        Map<Long, CommentRef> byId = new HashMap<>();
        for (CommentRef ref : source) {
            if (ref.id > 0L) byId.put(ref.id, ref);
        }

        Map<Long, Long> resolvedRoots = new HashMap<>();
        List<CommentRef> topLevel = new ArrayList<>();
        Map<Long, List<CommentRef>> repliesByRoot = new HashMap<>();
        for (CommentRef ref : source) {
            if (ref.parentId <= 0L) {
                topLevel.add(ref);
                continue;
            }
            long root = resolveRoot(ref, byId, resolvedRoots, new HashSet<>());
            repliesByRoot.computeIfAbsent(root, ignored -> new ArrayList<>()).add(ref);
        }

        LinkedHashSet<Integer> ordered = new LinkedHashSet<>();
        for (CommentRef root : topLevel) {
            ordered.add(root.sourceIndex);
            List<CommentRef> replies = repliesByRoot.get(root.id);
            if (replies == null) continue;
            for (CommentRef reply : replies) ordered.add(reply.sourceIndex);
        }

        // Preserve source order for orphaned or partially paged threads.
        for (CommentRef ref : source) ordered.add(ref.sourceIndex);
        return new ArrayList<>(ordered);
    }

    private static long resolveRoot(
        CommentRef ref,
        Map<Long, CommentRef> byId,
        Map<Long, Long> cache,
        Set<Long> trail
    ) {
        Long cached = cache.get(ref.id);
        if (cached != null) return cached;
        if (!trail.add(ref.id)) return ref.id;
        if (ref.parentId <= 0L) {
            cache.put(ref.id, ref.id);
            return ref.id;
        }

        if (ref.rootId > 0L) {
            CommentRef declaredRoot = byId.get(ref.rootId);
            if (declaredRoot == null || declaredRoot.parentId <= 0L) {
                cache.put(ref.id, ref.rootId);
                return ref.rootId;
            }
        }

        CommentRef parent = byId.get(ref.parentId);
        long root = parent == null
            ? (ref.rootId > 0L ? ref.rootId : ref.parentId)
            : resolveRoot(parent, byId, cache, trail);
        cache.put(ref.id, root);
        return root;
    }
}
