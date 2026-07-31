package xyz.jjmxg.yiyunying.data.cache;

import java.util.LinkedHashSet;
import java.util.Set;

public final class LocalCacheFilter {
    public final Set<String> categories = new LinkedHashSet<>();
    public String sourceType = "";
    public String sourceId = "";
    public long fromTimeMs;
    public long toTimeMs;
    public String query = "";
    public Boolean protectedOnly;
}
