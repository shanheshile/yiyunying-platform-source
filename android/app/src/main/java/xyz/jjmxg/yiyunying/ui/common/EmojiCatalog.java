package xyz.jjmxg.yiyunying.ui.common;

/** Shared Emoji set used by chat-like composers across the app. */
public final class EmojiCatalog {
    private static final String[] VALUES = {
        "😀","😃","😄","😁","😆","🥹","😂","🙂","🙃","😉",
        "😊","🥰","😍","😘","😋","😎","🤔","🫡","😴","😭",
        "😤","😡","👍","👎","👏","🙏","💪","🎉","❤️","💔",
        "🔥","✨","🌹","🎁","✅","❌","📌","📷","🎵","💬"
    };

    private EmojiCatalog() { }

    public static String[] values() {
        return VALUES.clone();
    }
}
