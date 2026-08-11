package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import android.graphics.Color;

import androidx.core.graphics.ColorUtils;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Arrays;
import java.util.List;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.stream.Collectors;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 35)
public final class DialogThemeContractTest {
    @Test public void appDialogsStayOnSharedModalSurfaces() throws Exception {
        String javaSources = readTree("src/main/java");
        assertFalse(javaSources.contains("new AlertDialog.Builder"));
        assertFalse(javaSources.contains("new android.app.AlertDialog.Builder"));
        assertFalse(javaSources.contains("new MaterialAlertDialogBuilder"));
        assertEquals(count(javaSources, "new BottomSheetDialog"),
            count(javaSources, "GlassBottomSheet.prepare(" )
                + count(javaSources, "GlassBottomSheet.prepareFloating("));

        String glassAction = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/common/GlassActionDialog.java");
        assertTrue(glassAction.contains("ModalLayerGuard.protectBottomSheet(glass, context)"));
    }

    @Test public void customDialogTextUsesThemeAttributes() throws Exception {
        List<String> layouts = Arrays.asList(
            "bottom_sheet_contact_card_confirm.xml",
            "bottom_sheet_chat_business_detail.xml",
            "bottom_sheet_chat_gift_catalog.xml",
            "bottom_sheet_chat_transaction.xml",
            "dialog_qr_share.xml",
            "item_chat_gift_catalog.xml"
        );
        Pattern fixedText = Pattern.compile(
            "(?:android:)?textColor=\\\"(?:#|@android:color/|@color/)"
        );
        for (String layout : layouts) {
            String source = read("src/main/res/layout/" + layout);
            assertFalse(layout + " contains a fixed text color", fixedText.matcher(source).find());
        }

        String qr = read("src/main/res/layout/dialog_qr_share.xml");
        assertTrue(qr.contains("app:cardBackgroundColor=\"@android:color/white\""));
        assertTrue(qr.contains("android:background=\"@android:color/white\""));

        String report = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/common/ContentReportDialog.java");
        assertTrue(report.contains("choice.setTextColor(onSurface)"));
        assertTrue(report.contains("detail.setTextColor(onSurface)"));
        assertTrue(report.contains("detail.setHintTextColor(onSurfaceVariant)"));
    }

    @Test public void profileHeadersAndInsetBaseKeepTheirContracts() throws Exception {
        for (String layout : Arrays.asList(
            "activity_user_profile.xml", "activity_conversation_permission.xml")) {
            String source = read("src/main/res/layout/" + layout);
            assertTrue(layout, source.contains("app:titleTextColor=\"?attr/colorOnSurface\""));
            assertTrue(layout, source.contains("app:navigationIconTint=\"?attr/colorOnSurface\""));
        }
        String base = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/common/SystemInsetActivity.java");
        assertTrue(base.contains("setContentView(int layoutResId)"));
        assertTrue(base.contains("setContentView(View view, ViewGroup.LayoutParams params)"));
        assertTrue(base.contains("isInMultiWindowMode()"));
    }

    @Test public void destructiveDialogActionsUseOnErrorInBothThemes() throws Exception {
        String colors = read("src/main/res/values/colors.xml");
        String nightColors = read("src/main/res/values-night/colors.xml");
        String themes = read("src/main/res/values/themes.xml");
        String nightThemes = read("src/main/res/values-night/themes.xml");
        assertTrue(colors.contains("name=\"on_error\""));
        assertTrue(nightColors.contains("name=\"on_error\""));
        assertTrue(themes.contains("name=\"colorOnError\">@color/on_error"));
        assertTrue(nightThemes.contains("name=\"colorOnError\">@color/on_error"));

        for (String relative : Arrays.asList(
            "src/main/java/xyz/jjmxg/yiyunying/ui/common/YiyunyingDialogBuilder.java",
            "src/main/java/xyz/jjmxg/yiyunying/ui/common/DynamicFormDialog.java"
        )) {
            String source = read(relative);
            assertTrue(relative, source.contains("colorOnError"));
            assertFalse(relative, Pattern.compile(
                "setTextColor\\([^;]*(?:Color\\.WHITE|R\\.color\\.white)",
                Pattern.DOTALL).matcher(source).find());
        }
    }

    @Test public void sharedStatusAndActionSurfacesUseMatchingThemeForegrounds() throws Exception {
        String badge = read("src/main/res/drawable/bg_unread_badge.xml");
        assertTrue(badge.contains("android:color=\"?attr/colorError\""));

        Pattern textBadge = Pattern.compile(
            "<TextView\\b[^>]*@drawable/bg_unread_badge[^>]*/>", Pattern.DOTALL);
        int textBadgeCount = 0;
        Path layoutRoot = locate("src/main/res/layout");
        try (java.util.stream.Stream<Path> paths = Files.list(layoutRoot)) {
            for (Path layout : paths.filter(path -> path.toString().endsWith(".xml"))
                    .collect(Collectors.toList())) {
                Matcher matcher = textBadge.matcher(
                    new String(Files.readAllBytes(layout), StandardCharsets.UTF_8));
                while (matcher.find()) {
                    textBadgeCount++;
                    assertTrue(layout.getFileName().toString(),
                        matcher.group().contains("android:textColor=\"?attr/colorOnError\""));
                }
            }
        }
        assertEquals(9, textBadgeCount);

        String call = read("src/main/res/layout/activity_voice_call.xml");
        assertTrue(call.contains("app:backgroundTint=\"?attr/colorError\""));
        assertTrue(call.contains("app:iconTint=\"?attr/colorOnError\""));
    }

    @Test public void corePaletteKeepsReadableForegroundBackgroundPairs() throws Exception {
        for (String directory : Arrays.asList("values", "values-night")) {
            String colors = read("src/main/res/" + directory + "/colors.xml");
            assertContrast(colors, "on_primary", "primary");
            assertContrast(colors, "on_primary_container", "primary_container");
            assertContrast(colors, "on_secondary", "secondary");
            assertContrast(colors, "on_secondary_container", "secondary_container");
            assertContrast(colors, "on_error", "error");
            assertContrast(colors, "on_surface", "surface");
            assertContrast(colors, "on_surface_variant", "surface_container");
            assertContrast(colors, "tertiary", "surface");
            assertContrast(colors, "success", "surface");
            assertContrast(colors, "warning", "surface");
            for (String accent : Arrays.asList("blue", "teal", "rose")) {
                assertContrast(colors, "on_primary", "accent_" + accent);
                assertContrast(colors, "on_accent_" + accent + "_container",
                    "accent_" + accent + "_container");
            }

            String themes = read("src/main/res/" + directory + "/themes.xml");
            for (String mapping : Arrays.asList(
                "colorOnSecondaryContainer\">@color/on_secondary_container",
                "colorSurfaceContainer\">@color/surface_container",
                "colorSurfaceContainerHigh\">@color/surface_container_high",
                "colorOutlineVariant\">@color/outline_variant")) {
                assertTrue(directory + " missing " + mapping, themes.contains(mapping));
            }
        }
    }

    @Test public void fixedLayoutColorsStayLimitedToHighContrastMediaSurfaces() throws Exception {
        List<String> allowed = Arrays.asList(
            "activity_file_preview.xml",
            "activity_friend_qr.xml",
            "activity_image_gallery.xml",
            "activity_in_app_capture.xml",
            "activity_media_picker.xml",
            "activity_voice_call.xml",
            "dialog_qr_share.xml",
            "item_banner.xml",
            "item_moment_media.xml"
        );
        Pattern fixed = Pattern.compile(
            "(?:#[0-9A-Fa-f]{6,8}|@android:color/(?:white|black)|@color/white)");
        Path layoutRoot = locate("src/main/res/layout");
        try (java.util.stream.Stream<Path> paths = Files.list(layoutRoot)) {
            for (Path layout : paths.filter(path -> path.toString().endsWith(".xml"))
                    .collect(Collectors.toList())) {
                String source = new String(Files.readAllBytes(layout), StandardCharsets.UTF_8);
                if (fixed.matcher(source).find()) {
                    assertTrue(layout.getFileName().toString(),
                        allowed.contains(layout.getFileName().toString()));
                }
            }
        }
    }

    @Test public void sharedAccentConsumersKeepForegroundAndContainerInTheSameTheme() throws Exception {
        String adapter = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/chat/ChatAdapter.java");
        assertTrue(adapter.contains("colorOnPrimaryContainer"));
        assertTrue(adapter.contains("colorSecondaryContainer"));
        assertTrue(adapter.contains("colorOnSecondaryContainer"));

        String chat = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/chat/ChatActivity.java");
        assertTrue(chat.contains(
            "ThemeColors.resolve(this, com.google.android.material.R.attr.colorOnPrimaryContainer"));

        String audio = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/chat/InlineAudioPlayerView.java");
        assertTrue(audio.contains("ThemeColors.onPrimary(getContext())"));

        String groupSpace = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/chat/GroupSpaceActivity.java");
        assertFalse(groupSpace.contains("getColor(R.color.primary)"));

        for (String layout : Arrays.asList("item_local_cache.xml", "activity_role_permission.xml")) {
            String source = read("src/main/res/layout/" + layout);
            assertFalse(layout, Pattern.compile(
                "(?:@color/(?:primary|on_primary|primary_container|on_primary_container"
                    + "|secondary_container|on_secondary_container))")
                .matcher(source).find());
        }
    }

    @Test public void outlineTokensRemainBordersRatherThanBodyText() throws Exception {
        Path layoutRoot = locate("src/main/res/layout");
        try (java.util.stream.Stream<Path> paths = Files.list(layoutRoot)) {
            for (Path layout : paths.filter(path -> path.toString().endsWith(".xml"))
                    .filter(path -> !path.getFileName().toString().contains("forum"))
                    .collect(Collectors.toList())) {
                String source = new String(Files.readAllBytes(layout), StandardCharsets.UTF_8);
                assertFalse(layout.getFileName().toString(),
                    source.contains("android:textColor=\"@color/outline\""));
            }
        }
        String featureRow = read("src/main/res/drawable/bg_feature_row.xml");
        assertTrue(featureRow.contains("android:color=\"?attr/colorOutlineVariant\""));
        assertFalse(featureRow.contains("#DCE7F5"));
    }

    @Test public void serverAccentCannotOverrideButtonsWithUnreadableText() {
        assertEquals(Color.BLACK,
            FestivalThemePresenter.readableAccent("#000000", Color.WHITE, Color.RED));
        assertEquals(Color.RED,
            FestivalThemePresenter.readableAccent("#FFFFFF", Color.WHITE, Color.RED));
        assertEquals(Color.RED,
            FestivalThemePresenter.readableAccent("#80FFFFFF", Color.BLACK, Color.RED));
        assertEquals(Color.RED,
            FestivalThemePresenter.readableAccent("not-a-color", Color.WHITE, Color.RED));
    }

    private static int count(String value, String needle) {
        int result = 0;
        int offset = 0;
        while ((offset = value.indexOf(needle, offset)) >= 0) {
            result++;
            offset += needle.length();
        }
        return result;
    }

    private static void assertContrast(String source, String foreground, String background) {
        double contrast = ColorUtils.calculateContrast(
            Color.parseColor(color(source, foreground)),
            Color.parseColor(color(source, background)));
        assertTrue(foreground + " on " + background + " contrast=" + contrast,
            contrast >= 4.5d);
    }

    private static String color(String source, String name) {
        Matcher matcher = Pattern.compile(
            "<color\\s+name=\"" + Pattern.quote(name) + "\">(#[0-9A-Fa-f]{6,8})</color>")
            .matcher(source);
        assertTrue("missing color " + name, matcher.find());
        return matcher.group(1);
    }

    private static String readTree(String relative) throws Exception {
        Path root = locate(relative);
        try (java.util.stream.Stream<Path> paths = Files.walk(root)) {
            List<Path> files = paths
                .filter(path -> path.getFileName().toString().endsWith(".java"))
                .sorted()
                .collect(Collectors.toList());
            StringBuilder combined = new StringBuilder();
            for (Path file : files) {
                combined.append(new String(Files.readAllBytes(file), StandardCharsets.UTF_8));
            }
            return combined.toString();
        }
    }

    private static String read(String relative) throws Exception {
        return new String(Files.readAllBytes(locate(relative)), StandardCharsets.UTF_8);
    }

    private static Path locate(String relative) {
        Path direct = Path.of(relative);
        return Files.exists(direct) ? direct : Path.of("app").resolve(relative);
    }
}
