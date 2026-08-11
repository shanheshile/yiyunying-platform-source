package xyz.jjmxg.yiyunying.domain.module;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.List;

public final class FieldSpec {
    private final String key;
    private final String label;
    private final FieldType type;
    private final boolean required;
    private final String defaultValue;
    private final List<Option> options;

    private FieldSpec(String key, String label, FieldType type, boolean required, String defaultValue, List<Option> options) {
        this.key = key;
        this.label = label;
        this.type = type;
        this.required = required;
        this.defaultValue = defaultValue == null ? "" : defaultValue;
        this.options = Collections.unmodifiableList(new ArrayList<>(options == null ? Collections.emptyList() : options));
    }

    public static FieldSpec of(String key, String label) {
        return new FieldSpec(key, label, FieldType.TEXT, false, "", Collections.emptyList());
    }

    public static FieldSpec required(String key, String label) {
        return new FieldSpec(key, label, FieldType.TEXT, true, "", Collections.emptyList());
    }

    public static FieldSpec typed(String key, String label, FieldType type, boolean required) {
        return new FieldSpec(key, label, type, required, "", Collections.emptyList());
    }

    /** A closed value-to-label selector. UI uses labels; requests use values only. */
    public static FieldSpec select(String key, String label, boolean required, Option... options) {
        return new FieldSpec(key, label, FieldType.SELECT, required, "", Arrays.asList(options));
    }

    public static Option option(String value, String label) {
        return new Option(value, label);
    }

    public FieldSpec withDefault(String value) {
        return new FieldSpec(key, label, type, required, value, options);
    }

    public String key() {
        return key;
    }

    public String label() {
        return label;
    }

    public FieldType type() {
        return type;
    }

    public boolean required() {
        return required;
    }

    public String defaultValue() {
        return defaultValue;
    }

    public List<Option> options() {
        return options;
    }

    public Option optionForValue(String value) {
        String normalized = value == null ? "" : value.trim();
        for (Option option : options) {
            if (option.value.equals(normalized) || option.label.equals(normalized)) return option;
        }
        return null;
    }

    public boolean allowsValue(String value) {
        return optionForValue(value) != null;
    }

    public static final class Option {
        private final String value;
        private final String label;

        private Option(String value, String label) {
            this.value = value == null ? "" : value.trim();
            this.label = label == null ? "" : label.trim();
            if (this.value.isEmpty() || this.label.isEmpty()) {
                throw new IllegalArgumentException("选择项的内部值和中文名称不能为空");
            }
        }

        public String value() { return value; }
        public String label() { return label; }
    }
}
