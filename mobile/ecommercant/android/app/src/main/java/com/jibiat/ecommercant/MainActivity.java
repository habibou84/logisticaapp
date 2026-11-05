package com.jibiat.ecommercant;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;
import com.jibiat.ecommercant.print.PrintPlugin;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Impression des étiquettes et bons de retour (la vue web ignore window.print)
        registerPlugin(PrintPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
