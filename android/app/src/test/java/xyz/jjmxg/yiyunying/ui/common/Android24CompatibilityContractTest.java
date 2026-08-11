package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;

import org.junit.Test;

public final class Android24CompatibilityContractTest {
    @Test public void auditFilterUsesCompatTooltipOnAndroid24() throws Exception {
        String source = read(
            "src/main/java/xyz/jjmxg/yiyunying/ui/module/GenericModuleFragment.java");
        assertTrue(source.contains("import androidx.appcompat.widget.TooltipCompat;"));
        assertTrue(source.contains(
            "TooltipCompat.setTooltipText(binding.auditFilterButton, \"当前：\" + label);"));
        assertFalse(source.contains("binding.auditFilterButton.setTooltipText("));
    }

    @Test public void navigationBarAppearanceUsesApi27QualifiedThemes() throws Exception {
        String dayBase = read("src/main/res/values/themes.xml");
        String nightBase = read("src/main/res/values-night/themes.xml");
        String dayApi27 = read("src/main/res/values-v27/themes.xml");
        String nightApi27 = read("src/main/res/values-night-v27/themes.xml");

        assertFalse(dayBase.contains("android:windowLightNavigationBar"));
        assertFalse(nightBase.contains("android:windowLightNavigationBar"));
        assertTrue(dayApi27.contains(
            "<item name=\"android:windowLightNavigationBar\">true</item>"));
        assertTrue(nightApi27.contains(
            "<item name=\"android:windowLightNavigationBar\">false</item>"));
    }

    private static String read(String relative) throws Exception {
        Path direct = Path.of(relative);
        Path path = Files.exists(direct) ? direct : Path.of("app").resolve(relative);
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }
}
