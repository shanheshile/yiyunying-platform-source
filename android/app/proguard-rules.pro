-keepattributes Signature
-keepattributes *Annotation*
-dontwarn javax.annotation.**
-dontwarn org.conscrypt.**

# JSON payloads are handled through JsonElement, but these DTOs are reflected by Gson.
-keep class xyz.jjmxg.yiyunying.data.model.** { *; }

# The user edition loads the bundled offline recognizer through a flavor-safe bridge.
-keep class xyz.jjmxg.yiyunying.speech.VoskOfflineSpeechEngine { public *; }
-keep class org.vosk.** { *; }
-dontwarn org.vosk.**
