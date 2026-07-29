package xyz.jjmxg.yiyunying.domain.module;

import java.util.Arrays;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

public final class ActionSpec {
    private final String title;
    private final String method;
    private final String pathTemplate;
    private final List<FieldSpec> fields;
    private final Map<String, String> fixedValues;
    private final boolean confirmationRequired;
    private final boolean destructive;
    private final boolean itemAction;
    private final boolean refreshAfter;
    private final boolean idempotent;

    private ActionSpec(Builder builder) {
        title = builder.title;
        method = builder.method;
        pathTemplate = builder.pathTemplate;
        fields = Collections.unmodifiableList(builder.fields);
        fixedValues = Collections.unmodifiableMap(new LinkedHashMap<>(builder.fixedValues));
        confirmationRequired = builder.confirmationRequired;
        destructive = builder.destructive;
        itemAction = builder.itemAction;
        refreshAfter = builder.refreshAfter;
        idempotent = builder.idempotent;
    }

    public static Builder builder(String title, String method, String pathTemplate) {
        return new Builder(title, method, pathTemplate);
    }

    public String title() {
        return title;
    }

    public String method() {
        return method;
    }

    public String pathTemplate() {
        return pathTemplate;
    }

    public List<FieldSpec> fields() {
        return fields;
    }

    public Map<String, String> fixedValues() {
        return fixedValues;
    }

    public boolean confirmationRequired() {
        return confirmationRequired;
    }

    public boolean destructive() {
        return destructive;
    }

    public boolean itemAction() {
        return itemAction;
    }

    public boolean refreshAfter() {
        return refreshAfter;
    }

    public boolean idempotent() {
        return idempotent;
    }

    public static final class Builder {
        private final String title;
        private final String method;
        private final String pathTemplate;
        private List<FieldSpec> fields = Collections.emptyList();
        private final Map<String, String> fixedValues = new LinkedHashMap<>();
        private boolean confirmationRequired;
        private boolean destructive;
        private boolean itemAction;
        private boolean refreshAfter = true;
        private boolean idempotent;

        private Builder(String title, String method, String pathTemplate) {
            this.title = title;
            this.method = method.toUpperCase(Locale.ROOT);
            this.pathTemplate = pathTemplate;
        }

        public Builder fields(FieldSpec... values) {
            fields = Arrays.asList(values);
            return this;
        }

        public Builder fixed(String key, String value) {
            fixedValues.put(key, value);
            return this;
        }

        public Builder confirm(boolean destructive) {
            confirmationRequired = true;
            this.destructive = destructive;
            return this;
        }

        public Builder item() {
            itemAction = true;
            return this;
        }

        public Builder noRefresh() {
            refreshAfter = false;
            return this;
        }

        public Builder idempotent() {
            idempotent = true;
            return this;
        }

        public ActionSpec build() {
            return new ActionSpec(this);
        }
    }
}
