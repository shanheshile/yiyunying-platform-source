package xyz.jjmxg.yiyunying.data.cache;

public final class LocalCacheEntry {
    public long id;
    public String accountKey = "";
    public String sourceType = "other";
    public String sourceId = "";
    public String sourceTitle = "其他来源";
    public String category = AutoCachePolicyStore.FILE;
    public String localPath = "";
    public String remoteUrl = "";
    public String displayName = "缓存内容";
    public String mimeType = "application/octet-stream";
    public long sizeBytes;
    public long createdAtMs;
    public long accessedAtMs;
    public boolean protectedFromCleanup;
    public long externalDownloadId = -1L;
    public String originKey = "";
}
